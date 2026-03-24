package org.openstatic;

import javax.sound.sampled.*;
import java.io.*;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;
import java.util.regex.Pattern;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;

/**
 * Utility that connects to a live Icecast/Shoutcast URL or reads audio from
 * stdin (containerized or raw PCM), decodes the audio, and
 * writes out "clips" whenever the stream is not silent.  Each clip is written
 * to a WAV file inside a folder named for the current date; the filename is the
 * time when the clip began.
 *
 * The detector is extremely simple: it converts the incoming stream to
 * signed 16‑bit PCM and computes a short‑term RMS value.  When the RMS drops
 * below the configured threshold for a configurable amount of time the
 * collector closes the current clip and waits for new audio.
 */
public class StreamRecorder {
    private static final String ANSI_RESET = "\u001B[0m";
    private static final String ANSI_RED = "\u001B[31m";
    private static final String ANSI_GREEN = "\u001B[32m";
    private static final String ANSI_YELLOW = "\u001B[33m";
    private static final String ANSI_BLUE = "\u001B[34m";
    private static final String ANSI_CYAN = "\u001B[36m";
    private static final long STDOUT_READ_POLL_MILLIS = 20L;
    private static final long DEFAULT_STDOUT_PAD_DELAY_MILLIS = 500L;
    private static final long DEFAULT_INPUT_DEJITTER_MILLIS = 250L;
    private static final long PIPE_RESTART_BACKOFF_MILLIS = 10000L;
    private static final double AUTO_GAIN_TARGET_DB = -12.0;
    private static final double AUTO_GAIN_MAX_DB = 30.0;
    private static final double AUTO_GAIN_ATTACK_DB_PER_SEC = 15.0;
    private static final double AUTO_GAIN_RELEASE_DB_PER_SEC = 25.0;
    private final URL streamUrl;
    private final InputStream stdinInput;
    private final boolean stdinMode;
    private final boolean stdinRawMode;
    private final AudioFormat stdinRawFormat;
    private final Path baseDir;
    private final double silenceThresholdDb;
    private final double silenceDurationSeconds;
    private final RmsGate rmsGate;
    private final float outputSampleRate;
    private final int outputChannels;
    private final int outputBitDepth;
    private final boolean outputBigEndian;
    private final List<String> onWriteCommand;
    private final String streamNameOverride;
    private volatile boolean running = true;
    private volatile String streamTitle;
    private volatile String streamLabel;
    private volatile Integer requiredDcsCode;
    private volatile String requiredDcsLabel;
    private volatile Double requiredCtcssToneHz;
    private volatile String requiredCtcssLabel;
    private volatile long inputDejitterMillis;
    private volatile double gateHoldSeconds;
    private volatile boolean stdoutEnabled;
    private volatile boolean stdoutRawMode;
    private volatile AudioFormat stdoutRawFormat;
    private volatile boolean stdoutPadMode;
    private volatile long stdoutPadDelayMillis;
    private volatile boolean stdoutRawConversionWarned;
    private volatile boolean stdoutConfigLogged;
    private volatile boolean deviceOutputEnabled;
    private volatile String deviceOutputSelector;
    private volatile boolean deviceOutputConversionWarned;
    private volatile boolean deviceConfigLogged;
    private final List<String> pipeOutputCommands;
    private volatile boolean pipeRawMode;
    private volatile AudioFormat pipeRawFormat;
    private volatile boolean pipeRawPadMode;
    private volatile long pipeRawPadDelayMillis;
    private volatile boolean pipeRawConversionWarned;
    private volatile boolean pipeConfigLogged;
    private volatile String pipeInputCommand;
    private volatile boolean pipeInputRawMode;
    private volatile AudioFormat pipeInputRawFormat;
    private volatile Process pipeInputProcess;
    private volatile double outputGainDb;
    private volatile boolean autoGainEnabled;
    private volatile double smoothedAutoGainDb;
    private volatile ApiWebSocketServer apiWebSocketServer;
    private static final DateTimeFormatter DATE_FMT = DateTimeFormatter.ISO_DATE;
    private static final DateTimeFormatter TIME_FMT = DateTimeFormatter.ofPattern("HHmmss");
    private static final DateTimeFormatter LOG_TIME_FMT = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss");

    public StreamRecorder(URL streamUrl,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram) {
        this(streamUrl, baseDir, silenceThresholdDb, silenceDurationSeconds, outputSampleRate,
            outputChannels, outputBitDepth, outputBigEndian, onWriteProgram, null);
    }

    public StreamRecorder(URL streamUrl,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(streamUrl, null, false, false, null, streamUrl.getPath(), baseDir, silenceThresholdDb,
            silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth, outputBigEndian,
                onWriteProgram, streamNameOverride);
    }

    public StreamRecorder(InputStream stdinInput,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram) {
        this(stdinInput, baseDir, silenceThresholdDb, silenceDurationSeconds, outputSampleRate,
            outputChannels, outputBitDepth, outputBigEndian, onWriteProgram, null);
    }

    public StreamRecorder(InputStream stdinInput,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(null, stdinInput, true, false, null, "stdin", baseDir, silenceThresholdDb,
            silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth, outputBigEndian,
                onWriteProgram, streamNameOverride);
    }

    public StreamRecorder(InputStream stdinInput,
                          AudioFormat stdinRawFormat,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram) {
        this(stdinInput, stdinRawFormat, baseDir, silenceThresholdDb, silenceDurationSeconds,
            outputSampleRate, outputChannels, outputBitDepth, outputBigEndian, onWriteProgram, null);
    }

    public StreamRecorder(InputStream stdinInput,
                          AudioFormat stdinRawFormat,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                  boolean outputBigEndian,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(null, stdinInput, true, true, stdinRawFormat, "stdin", baseDir, silenceThresholdDb,
            silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth, outputBigEndian,
                onWriteProgram, streamNameOverride);
    }

    private StreamRecorder(URL streamUrl,
                           InputStream stdinInput,
                           boolean stdinMode,
                           boolean stdinRawMode,
                           AudioFormat stdinRawFormat,
                           String initialLabel,
                           Path baseDir,
                           double silenceThresholdDb,
                           double silenceDurationSeconds,
                           float outputSampleRate,
                           int outputChannels,
                           int outputBitDepth,
                           boolean outputBigEndian,
                           String onWriteProgram,
                           String streamNameOverride) {
        this.streamUrl = streamUrl;
        this.stdinInput = stdinInput;
        this.stdinMode = stdinMode;
        this.stdinRawMode = stdinRawMode;
        this.stdinRawFormat = stdinRawFormat;
        this.baseDir = baseDir;
        this.silenceThresholdDb = silenceThresholdDb;
        this.silenceDurationSeconds = silenceDurationSeconds;
        this.rmsGate = new RmsGate(silenceThresholdDb);
        this.outputSampleRate = outputSampleRate;
        this.outputChannels = outputChannels;
        this.outputBitDepth = outputBitDepth;
        this.outputBigEndian = outputBigEndian;
        this.onWriteCommand = parseCommandLine(onWriteProgram);
        this.streamNameOverride = isBlank(streamNameOverride) ? null : streamNameOverride.trim();
        this.streamTitle = normalizeStreamTitle((this.streamNameOverride != null)
            ? this.streamNameOverride
            : initialLabel);
        this.streamLabel = sanitizeStreamName(this.streamTitle);
        this.requiredDcsCode = null;
        this.requiredDcsLabel = null;
        this.requiredCtcssToneHz = null;
        this.requiredCtcssLabel = null;
        this.inputDejitterMillis = DEFAULT_INPUT_DEJITTER_MILLIS;
        this.gateHoldSeconds = 1.0;
        this.stdoutEnabled = false;
        this.stdoutRawMode = false;
        this.stdoutRawFormat = null;
        this.stdoutPadMode = false;
        this.stdoutPadDelayMillis = DEFAULT_STDOUT_PAD_DELAY_MILLIS;
        this.stdoutRawConversionWarned = false;
        this.stdoutConfigLogged = false;
        this.deviceOutputEnabled = false;
        this.deviceOutputSelector = null;
        this.deviceOutputConversionWarned = false;
        this.deviceConfigLogged = false;
        this.pipeOutputCommands = new ArrayList<>();
        this.pipeRawMode = false;
        this.pipeRawFormat = null;
        this.pipeRawPadMode = false;
        this.pipeRawPadDelayMillis = DEFAULT_STDOUT_PAD_DELAY_MILLIS;
        this.pipeRawConversionWarned = false;
        this.pipeConfigLogged = false;
        this.pipeInputCommand = null;
        this.pipeInputRawMode = false;
        this.pipeInputRawFormat = null;
        this.pipeInputProcess = null;
        this.outputGainDb = 0.0;
        this.autoGainEnabled = false;
        this.smoothedAutoGainDb = 0.0;
        this.apiWebSocketServer = null;
    }

    public void stop() {
        running = false;
        Process activePipeInputProcess = this.pipeInputProcess;
        if (activePipeInputProcess != null) {
            activePipeInputProcess.destroy();
        }
        log("STOP", ANSI_YELLOW, "Shutdown requested, finishing current write before exit.");
    }

    public void setRequiredDcsCode(Integer dcsCode) {
        if (dcsCode == null) {
            this.requiredDcsCode = null;
            this.requiredDcsLabel = null;
            return;
        }
        if (dcsCode < 0 || dcsCode > 0x1FF) {
            throw new IllegalArgumentException("DCS code must be between 000 and 777 (octal)");
        }
        this.requiredDcsCode = dcsCode;
        this.requiredDcsLabel = formatDcsCode(dcsCode);
    }

    public void setRequiredCtcssTone(Double toneHz) {
        if (toneHz == null) {
            this.requiredCtcssToneHz = null;
            this.requiredCtcssLabel = null;
            return;
        }
        if (toneHz < 50.0 || toneHz > 300.0) {
            throw new IllegalArgumentException("CTCSS tone must be between 50.0 and 300.0 Hz");
        }
        this.requiredCtcssToneHz = toneHz;
        this.requiredCtcssLabel = formatCtcssTone(toneHz);
    }

    public void setGateHoldSeconds(double gateHoldSeconds) {
        if (gateHoldSeconds < 0.0) {
            throw new IllegalArgumentException("gate hold must be >= 0 seconds");
        }
        this.gateHoldSeconds = gateHoldSeconds;
    }

    public void setInputDejitterMillis(long inputDejitterMillis) {
        if (inputDejitterMillis < 0L) {
            throw new IllegalArgumentException("input de-jitter must be >= 0 milliseconds");
        }
        this.inputDejitterMillis = inputDejitterMillis;
    }

    public void setStdoutOutput(boolean enabled,
                                boolean rawMode,
                                AudioFormat rawFormat,
                                boolean padMode,
                                long padDelayMillis) {
        if (!enabled) {
            this.stdoutEnabled = false;
            this.stdoutRawMode = false;
            this.stdoutRawFormat = null;
            this.stdoutPadMode = false;
            this.stdoutPadDelayMillis = DEFAULT_STDOUT_PAD_DELAY_MILLIS;
            this.stdoutRawConversionWarned = false;
            this.stdoutConfigLogged = false;
            return;
        }

        if (rawMode && rawFormat == null) {
            throw new IllegalArgumentException("stdout raw mode requires a format");
        }

        this.stdoutEnabled = true;
        this.stdoutRawMode = rawMode;
        this.stdoutRawFormat = rawMode ? rawFormat : null;
        this.stdoutPadMode = rawMode && padMode;
        this.stdoutPadDelayMillis = Math.max(0L, padDelayMillis);
        this.stdoutRawConversionWarned = false;
        this.stdoutConfigLogged = false;
    }

    public void setApiWebSocketServer(ApiWebSocketServer apiWebSocketServer) {
        this.apiWebSocketServer = apiWebSocketServer;
    }

    public void setDeviceOutput(String deviceSelector) {
        if (isBlank(deviceSelector)) {
            this.deviceOutputEnabled = false;
            this.deviceOutputSelector = null;
            this.deviceOutputConversionWarned = false;
            this.deviceConfigLogged = false;
            return;
        }

        this.deviceOutputEnabled = true;
        this.deviceOutputSelector = deviceSelector.trim();
        this.deviceOutputConversionWarned = false;
        this.deviceConfigLogged = false;
    }

    public void setPipeOutputs(String[] pipeCommands) {
        this.pipeOutputCommands.clear();
        if (pipeCommands != null) {
            for (String pipeCommand : pipeCommands) {
                if (!isBlank(pipeCommand)) {
                    this.pipeOutputCommands.add(pipeCommand.trim());
                }
            }
        }
        this.pipeConfigLogged = false;
    }

    public void setPipeRawOutput(boolean rawMode, AudioFormat rawFormat) {
        setPipeRawOutput(rawMode, rawFormat, false, DEFAULT_STDOUT_PAD_DELAY_MILLIS);
    }

    public void setPipeRawOutput(boolean rawMode,
                                 AudioFormat rawFormat,
                                 boolean padMode,
                                 long padDelayMillis) {
        if (!rawMode) {
            this.pipeRawMode = false;
            this.pipeRawFormat = null;
            this.pipeRawPadMode = false;
            this.pipeRawPadDelayMillis = DEFAULT_STDOUT_PAD_DELAY_MILLIS;
            this.pipeRawConversionWarned = false;
            this.pipeConfigLogged = false;
            return;
        }
        if (rawFormat == null) {
            throw new IllegalArgumentException("pipe raw mode requires a format");
        }
        this.pipeRawMode = true;
        this.pipeRawFormat = rawFormat;
        this.pipeRawPadMode = padMode;
        this.pipeRawPadDelayMillis = Math.max(0L, padDelayMillis);
        this.pipeRawConversionWarned = false;
        this.pipeConfigLogged = false;
    }

    public void setPipeInput(String command, boolean rawMode, AudioFormat rawFormat) {
        if (isBlank(command)) {
            this.pipeInputCommand = null;
            this.pipeInputRawMode = false;
            this.pipeInputRawFormat = null;
            return;
        }
        if (rawMode && rawFormat == null) {
            throw new IllegalArgumentException("pipe-input raw mode requires a format");
        }

        this.pipeInputCommand = command.trim();
        this.pipeInputRawMode = rawMode;
        this.pipeInputRawFormat = rawMode ? rawFormat : null;
    }

    public void setGainControl(double gainDb, boolean autoGainEnabled) {
        if (!Double.isFinite(gainDb)) {
            throw new IllegalArgumentException("gain must be a finite numeric value in dB");
        }
        if (gainDb < -60.0 || gainDb > 60.0) {
            throw new IllegalArgumentException("gain must be between -60 and +60 dB");
        }
        this.outputGainDb = gainDb;
        this.autoGainEnabled = autoGainEnabled;
        this.smoothedAutoGainDb = 0.0;
    }

    private boolean canWriteRecordings() {
        return this.baseDir != null;
    }

    public void run() throws Exception {
        logHookConfiguration();
        if (!isBlank(this.pipeInputCommand)) {
            runFromPipeInput();
            return;
        }
        if (stdinMode) {
            runFromStdin();
            return;
        }

        while (running) {
            try {
                log("CONNECT", ANSI_BLUE, "Connecting to " + streamUrl);
                HttpURLConnection conn = (HttpURLConnection) streamUrl.openConnection();
                conn.setRequestProperty("User-Agent", "radio-pipe/1.0");
                conn.setConnectTimeout(10000);
                conn.setReadTimeout(15000);
                conn.connect();
                updateStreamLabel(conn);
                log("STREAM", ANSI_CYAN,
                        "Connected: name=" + streamLabel
                                + ", type=" + valueOrUnknown(conn.getContentType())
                                + ", bitrate=" + valueOrUnknown(conn.getHeaderField("icy-br")) + " kbps");
                try (InputStream raw = new BufferedInputStream(conn.getInputStream())) {
                    processInput(raw);
                }
            } catch (Exception e) {
                logError("Connection error: " + e.getMessage());
                e.printStackTrace(System.err);
                if (running) {
                    log("RETRY", ANSI_YELLOW, "Retrying in 5 seconds...");
                    Thread.sleep(5000);
                }
            }
        }
    }

    private void runFromStdin() throws Exception {
        if (stdinRawMode) {
            log("CONNECT", ANSI_BLUE, "Reading raw PCM audio from stdin");
            log("STREAM", ANSI_CYAN,
                    "Connected: name=" + streamLabel
                            + ", type=stdin/raw, bitrate=unknown");
            log("FORMAT", ANSI_CYAN,
                    String.format("Raw stdin format: %.0f Hz, %d channel(s), %d-bit %s, %s-endian",
                            stdinRawFormat.getSampleRate(),
                            stdinRawFormat.getChannels(),
                            stdinRawFormat.getSampleSizeInBits(),
                            stdinRawFormat.getEncoding(),
                            stdinRawFormat.isBigEndian() ? "big" : "little"));
        } else {
            log("CONNECT", ANSI_BLUE, "Reading audio from stdin");
            log("STREAM", ANSI_CYAN,
                    "Connected: name=" + streamLabel
                            + ", type=stdin, bitrate=unknown");
        }
        try (InputStream raw = new BufferedInputStream(this.stdinInput)) {
            if (stdinRawMode) {
                processRawInput(raw);
            } else {
                processInput(raw);
            }
        }
        if (running) {
            log("STOP", ANSI_YELLOW, "stdin ended, recorder exiting.");
        }
    }

    private void runFromPipeInput() throws Exception {
        String command = this.pipeInputCommand;
        if (isBlank(command)) {
            throw new IllegalStateException("pipe-input command is not configured");
        }

        while (running) {
            Process process = null;
            try {
                log("CONNECT", ANSI_BLUE, "Reading audio from pipe input command: " + command);
                ProcessBuilder pb = new ProcessBuilder(buildShellCommand(command));
                process = pb.start();
                this.pipeInputProcess = process;
                startPipeInputStderrLogger(command, process.getErrorStream());

                log("STREAM", ANSI_CYAN,
                        "Connected: name=" + streamLabel
                                + ", type=pipe-input, bitrate=unknown");
                if (this.pipeInputRawMode && this.pipeInputRawFormat != null) {
                    log("FORMAT", ANSI_CYAN,
                            "Raw pipe input format: " + describeAudioFormat(this.pipeInputRawFormat));
                }

                try (InputStream raw = new BufferedInputStream(process.getInputStream())) {
                    if (this.pipeInputRawMode && this.pipeInputRawFormat != null) {
                        processRawInput(raw, this.pipeInputRawFormat);
                    } else {
                        processInput(raw);
                    }
                }
            } catch (Exception e) {
                if (!running) {
                    break;
                }
                logError("Pipe-input command error: " + e.getMessage());
                e.printStackTrace(System.err);
            } finally {
                this.pipeInputProcess = null;
                if (process != null) {
                    process.destroy();
                    try {
                        process.waitFor(1000L, TimeUnit.MILLISECONDS);
                    } catch (InterruptedException interruptedException) {
                        Thread.currentThread().interrupt();
                    }
                }
            }

            if (running) {
                log("RETRY", ANSI_YELLOW,
                        "Pipe-input command ended, restarting in " + PIPE_RESTART_BACKOFF_MILLIS + " ms...");
                Thread.sleep(PIPE_RESTART_BACKOFF_MILLIS);
            }
        }
    }

    private void startPipeInputStderrLogger(String command, InputStream stderrStream) {
        Thread stderrThread = new Thread(() -> {
            try (BufferedReader errorReader = new BufferedReader(new InputStreamReader(stderrStream))) {
                String line;
                while ((line = errorReader.readLine()) != null) {
                    log("PIPE-IN", ANSI_BLUE, "[" + command + "] " + line);
                }
            } catch (IOException ignored) {
                // Stream closed.
            }
        }, "pipe-input-stderr-" + Math.abs(command.hashCode()));
        stderrThread.setDaemon(true);
        stderrThread.start();
    }

    private void processInput(InputStream raw) throws Exception {
        try (AudioInputStream audio = AudioSystem.getAudioInputStream(raw)) {
            processInput(audio);
        }
    }

    private void processRawInput(InputStream raw) throws Exception {
        try (AudioInputStream audio = new AudioInputStream(raw, this.stdinRawFormat, AudioSystem.NOT_SPECIFIED)) {
            processInput(audio);
        }
    }

    private void processRawInput(InputStream raw, AudioFormat rawFormat) throws Exception {
        try (AudioInputStream audio = new AudioInputStream(raw, rawFormat, AudioSystem.NOT_SPECIFIED)) {
            processInput(audio);
        }
    }

    private void processInput(AudioInputStream audio) throws Exception {
        AudioFormat baseFormat = audio.getFormat();
        log("FORMAT", ANSI_CYAN,
                String.format("Input format: %.0f Hz, %d channel(s), %s",
                        baseFormat.getSampleRate(),
                        baseFormat.getChannels(),
                        baseFormat.getEncoding()));

        AudioFormat decodedFormat = new AudioFormat(
                AudioFormat.Encoding.PCM_SIGNED,
                baseFormat.getSampleRate(),
                16,
                baseFormat.getChannels(),
                baseFormat.getChannels() * 2,
                baseFormat.getSampleRate(),
                false);

        AudioFormat outputFormat = new AudioFormat(
                AudioFormat.Encoding.PCM_SIGNED,
                outputSampleRate,
                outputBitDepth,
                outputChannels,
                outputChannels * (outputBitDepth / 8),
                outputSampleRate,
            outputBigEndian);

        try (AudioInputStream din = AudioSystem.getAudioInputStream(decodedFormat, audio)) {
            boolean outputConversionSupported = AudioSystem.isConversionSupported(outputFormat, decodedFormat);
            if (!outputConversionSupported) {
                log("FORMAT", ANSI_YELLOW,
                        "Requested output format is not supported by this JVM; using decoded stream format.");
            }
            AudioFormat activeOutputFormat = outputConversionSupported ? outputFormat : decodedFormat;
            log("READY", ANSI_GREEN,
                    String.format(
                        "Monitoring audio at %.0f Hz, %d channel(s), %d-bit; clip output %.0f Hz, %d channel(s), %d-bit %s-endian; silence threshold %.1f dB for %.1f s",
                            decodedFormat.getSampleRate(),
                            decodedFormat.getChannels(),
                            decodedFormat.getSampleSizeInBits(),
                            activeOutputFormat.getSampleRate(),
                            activeOutputFormat.getChannels(),
                            activeOutputFormat.getSampleSizeInBits(),
                        activeOutputFormat.isBigEndian() ? "big" : "little",
                            silenceThresholdDb,
                            silenceDurationSeconds));
            logDcsConfiguration();
            logCtcssConfiguration();
            logStdoutConfiguration(activeOutputFormat);
            logDeviceConfiguration(activeOutputFormat);
            logPipeConfiguration(activeOutputFormat);
            logGainConfiguration();
            processStream(din, decodedFormat, activeOutputFormat);
        }
    }

    private void processStream(AudioInputStream din, AudioFormat processingFormat, AudioFormat clipOutputFormat) throws IOException {
        int frameSize = processingFormat.getFrameSize();
        float frameRate = processingFormat.getFrameRate() > 0 ? processingFormat.getFrameRate() : processingFormat.getSampleRate();
        long framesForSilence = (long) (silenceDurationSeconds * frameRate);
        boolean dcsGateEnabled = (this.requiredDcsCode != null);
        boolean ctcssGateEnabled = (this.requiredCtcssToneHz != null);
        DcsDetector dcsStatusDetector = new DcsDetector(processingFormat);
        CtcssDetector ctcssStatusDetector = new CtcssDetector(processingFormat);
        DcsDetector dcsGateDetector = dcsGateEnabled ? new DcsDetector(processingFormat, this.requiredDcsCode) : null;
        CtcssDetector ctcssGateDetector = ctcssGateEnabled ? new CtcssDetector(processingFormat, this.requiredCtcssToneHz) : null;
        boolean dcsGateOpen = !dcsGateEnabled;
        boolean ctcssGateOpen = !ctcssGateEnabled;
        long gateHoldNanos = (this.gateHoldSeconds <= 0.0)
            ? 0L
            : Math.max(1L, Math.round(this.gateHoldSeconds * 1_000_000_000.0));
        long dcsGateHoldUntilNanos = Long.MIN_VALUE;
        long ctcssGateHoldUntilNanos = Long.MIN_VALUE;

        long statusClockNanos = System.nanoTime();
        boolean statusDcsGateOpen = dcsGateOpen;
        long statusDcsGateSinceNanos = statusClockNanos;
        boolean statusCtcssGateOpen = ctcssGateOpen;
        long statusCtcssGateSinceNanos = statusClockNanos;
        boolean statusRmsGateOpen = true;  // will be updated on first frame
        long statusRmsGateSinceNanos = statusClockNanos;
        double statusRmsDb = 0.0;  // current RMS level in dB
        double statusOutputDb = -100.0; // RMS level after all gates
        boolean statusGateOpen = false;
        String statusGateReason = "silence";
        long statusGateSinceNanos = statusClockNanos;
        boolean statusAudioDetected = false;
        long statusAudioDetectedSinceNanos = statusClockNanos;
        String statusDetectedDcsLabel = null;
        String statusDetectedDcsPolarity = null;
        int statusDcsConfidence = 0;
        String statusDetectedCtcssLabel = null;
        String lastUngatedDcsLabel = null;
        String lastUngatedDcsPolarity = null;
        long nextStatusEmitNanos = statusClockNanos + TimeUnit.MILLISECONDS.toNanos(250L);

        int padFramesPerTick = Math.max(1, (int) Math.round((STDOUT_READ_POLL_MILLIS / 1000.0) * frameRate));
        int ioChunkBytes = Math.max(frameSize, padFramesPerTick * frameSize);
        byte[] readBuffer = new byte[ioChunkBytes];
        ByteArrayOutputStream chunk = new ByteArrayOutputStream();
        long recordedFrames = 0;
        long soundFrames = 0;
        long silentFrames = 0;
        long chunkStartTime = 0;
        boolean activelyRecording = false;

        long inputDejitterTargetBytes = Math.round((this.inputDejitterMillis / 1000.0) * frameRate) * frameSize;
        if (this.inputDejitterMillis > 0L) {
            inputDejitterTargetBytes = Math.max(ioChunkBytes, inputDejitterTargetBytes);
            log("INPUT", ANSI_CYAN,
                    "Input de-jitter buffer enabled: " + this.inputDejitterMillis + " ms.");
        }
        ArrayDeque<byte[]> inputDejitterBuffer = new ArrayDeque<>();
        long inputDejitterBufferedBytes = 0L;
        boolean inputDejitterPrimed = (inputDejitterTargetBytes <= 0L);
        boolean inputEndOfStream = false;

        // Delay buffer for stdout pad mode: pre-filled with silence so output is always continuous
        ArrayDeque<byte[]> stdoutPadBuffer = new ArrayDeque<>();
        if (this.stdoutEnabled && this.stdoutRawMode && this.stdoutPadMode) {
            long targetBytes = Math.round(this.stdoutPadDelayMillis / 1000.0 * frameRate) * frameSize;
            if (targetBytes > 0) {
                stdoutPadBuffer.addLast(new byte[(int) targetBytes]);
            }
        }

        DeviceOutputSession deviceOutputSession = null;
        if (this.deviceOutputEnabled) {
            deviceOutputSession = openDeviceOutputSession(processingFormat, clipOutputFormat);
        }
        List<PipeOutputSession> pipeSessions = openPipeOutputSessions();
        ArrayDeque<byte[]> pipePadBuffer = new ArrayDeque<>();
        if (!pipeSessions.isEmpty() && this.pipeRawMode && this.pipeRawPadMode) {
            long targetBytes = Math.round(this.pipeRawPadDelayMillis / 1000.0 * frameRate) * frameSize;
            if (targetBytes > 0) {
                pipePadBuffer.addLast(new byte[(int) targetBytes]);
            }
        }

        BlockingQueue<StreamReadResult> readQueue = new LinkedBlockingQueue<>();
        Thread readerThread = new Thread(() -> {
            try {
                int n;
                while (running && (n = din.read(readBuffer)) != -1) {
                    byte[] copy = Arrays.copyOf(readBuffer, n);
                    readQueue.put(StreamReadResult.data(copy, n));
                }
                readQueue.put(StreamReadResult.eof());
            } catch (Exception readException) {
                try {
                    readQueue.put(StreamReadResult.error(readException));
                } catch (InterruptedException interruptedException) {
                    Thread.currentThread().interrupt();
                }
            }
        }, "audio-stream-reader");
        readerThread.setDaemon(true);
        readerThread.start();

        try {
            while (running) {
            StreamReadResult readResult;
            try {
                if (inputEndOfStream) {
                    readResult = readQueue.poll();
                } else {
                    readResult = readQueue.poll(STDOUT_READ_POLL_MILLIS, TimeUnit.MILLISECONDS);
                }
            } catch (InterruptedException interruptedException) {
                Thread.currentThread().interrupt();
                break;
            }

            if (readResult != null) {
                if (readResult.error != null) {
                    throw new IOException("Audio stream read failed", readResult.error);
                }
                if (readResult.endOfStream) {
                    inputEndOfStream = true;
                } else if (readResult.data != null && readResult.length > 0) {
                    inputDejitterBuffer.addLast(readResult.data);
                    inputDejitterBufferedBytes += readResult.length;
                }
            }

            while (!inputEndOfStream && (readResult = readQueue.poll()) != null) {
                if (readResult.error != null) {
                    throw new IOException("Audio stream read failed", readResult.error);
                }
                if (readResult.endOfStream) {
                    inputEndOfStream = true;
                    break;
                }
                if (readResult.data != null && readResult.length > 0) {
                    inputDejitterBuffer.addLast(readResult.data);
                    inputDejitterBufferedBytes += readResult.length;
                }
            }

            if (!inputDejitterPrimed && (inputDejitterBufferedBytes >= inputDejitterTargetBytes
                    || (inputEndOfStream && inputDejitterBufferedBytes > 0))) {
                inputDejitterPrimed = true;
            }

            boolean noAudioData = true;
            byte[] currentBuffer = null;
            int n = 0;
            if (inputDejitterPrimed && !inputDejitterBuffer.isEmpty()) {
                currentBuffer = inputDejitterBuffer.removeFirst();
                n = currentBuffer.length;
                inputDejitterBufferedBytes -= n;
                noAudioData = false;
            }

            if (noAudioData) {
                if (inputEndOfStream) {
                    break;
                }
                boolean useStallPadding = (this.stdoutEnabled && this.stdoutRawMode && this.stdoutPadMode)
                        || (!pipeSessions.isEmpty() && this.pipeRawMode && this.pipeRawPadMode);
                if (useStallPadding) {
                    int padBytes = padFramesPerTick * frameSize;
                    byte[] padSilence = new byte[padBytes];
                    if (this.stdoutEnabled && this.stdoutRawMode && this.stdoutPadMode) {
                        stdoutPadBuffer.addLast(padSilence);
                        drainStdoutPadBuffer(stdoutPadBuffer, padBytes, processingFormat);
                    }
                    if (!pipeSessions.isEmpty() && this.pipeRawMode && this.pipeRawPadMode) {
                        pipePadBuffer.addLast(padSilence);
                        drainPipePadBuffer(pipePadBuffer, padBytes, processingFormat, pipeSessions);
                    }

                    if (activelyRecording) {
                        chunk.write(padSilence, 0, padBytes);
                        recordedFrames += padFramesPerTick;
                        if (deviceOutputSession != null) {
                            writeDeviceOutput(deviceOutputSession, padSilence, padBytes, processingFormat);
                        }
                    }

                    silentFrames += padFramesPerTick;
                    if (silentFrames >= framesForSilence && chunk.size() > 0) {
                        if (canWriteRecordings()) {
                            log("SILENCE", ANSI_YELLOW,
                                String.format("Silence reached after %.1f s, closing clip.",
                                    recordedFrames / frameRate));
                        }
                        publishEvent("silenceDetected");
                        byte[] clipAudio = chunk.toByteArray();
                        double soundSeconds = soundFrames / frameRate;
                        if (soundSeconds > 1.0) {
                            if (canWriteRecordings()) {
                                writeChunk(clipAudio, processingFormat, clipOutputFormat, chunkStartTime);
                            }
                            if (this.stdoutEnabled && !this.stdoutRawMode) {
                                writeChunkToStdoutWav(clipAudio, processingFormat, clipOutputFormat);
                            }
                            if (!pipeSessions.isEmpty() && !this.pipeRawMode) {
                                writeChunkToPipeWav(pipeSessions, clipAudio, processingFormat, clipOutputFormat);
                            }
                        } else {
                            if (canWriteRecordings()) {
                                log("RECORD", ANSI_YELLOW,
                                        String.format("Discarding clip with only %.1f s of sound.",
                                                soundSeconds));
                            }
                        }
                        chunk.reset();
                        activelyRecording = false;
                        recordedFrames = 0;
                        soundFrames = 0;
                        silentFrames = 0;
                    }
                }
                statusOutputDb = -100.0;
                long idleNowNanos = System.nanoTime();
                nextStatusEmitNanos = publishStatusIfDue(
                        idleNowNanos,
                        nextStatusEmitNanos,
                    dcsGateEnabled,
                        statusDcsGateOpen,
                        statusDcsGateSinceNanos,
                    ctcssGateEnabled,
                        statusCtcssGateOpen,
                        statusCtcssGateSinceNanos,
                        statusRmsGateOpen,
                        statusRmsGateSinceNanos,
                        statusRmsDb,
                        statusOutputDb,
                        statusGateOpen,
                        statusGateReason,
                        statusGateSinceNanos,
                        statusAudioDetected,
                        statusAudioDetectedSinceNanos,
                        statusDetectedDcsLabel,
                        statusDetectedDcsPolarity,
                        statusDcsConfidence,
                        statusDetectedCtcssLabel,
                        this.streamUrl,
                        this.stdinMode,
                        processingFormat,
                        this.baseDir);
                continue;
            }

            long nowNanos = System.nanoTime();

            dcsStatusDetector.consume(currentBuffer, n, processingFormat);
            Integer detectedDcsCode = dcsStatusDetector.getDetectedCode();
            statusDetectedDcsLabel = (detectedDcsCode == null) ? null : formatDcsCode(detectedDcsCode.intValue());
            statusDetectedDcsPolarity = (detectedDcsCode == null) ? null : dcsStatusDetector.getPolarityLabel();
            statusDcsConfidence = dcsStatusDetector.getConfidenceScore();

            if (!dcsGateEnabled) {
                if (statusDetectedDcsLabel != null) {
                    if (!sameNullableText(statusDetectedDcsLabel, lastUngatedDcsLabel)
                            || !sameNullableText(statusDetectedDcsPolarity, lastUngatedDcsPolarity)) {
                        log("DCS", ANSI_CYAN,
                                "DCS " + statusDetectedDcsLabel
                                        + " detected (" + statusDetectedDcsPolarity
                                        + "), gate not configured.");
                    }
                } else if (lastUngatedDcsLabel != null) {
                    log("DCS", ANSI_YELLOW,
                            "DCS " + lastUngatedDcsLabel + " no longer detected.");
                }

                lastUngatedDcsLabel = statusDetectedDcsLabel;
                lastUngatedDcsPolarity = statusDetectedDcsPolarity;
            }

            boolean dcsRawMatch = !dcsGateEnabled || dcsGateDetector.consume(currentBuffer, n, processingFormat);
            if (dcsGateEnabled && dcsRawMatch && gateHoldNanos > 0L) {
                long holdUntil = nowNanos + gateHoldNanos;
                dcsGateHoldUntilNanos = (holdUntil < 0L) ? Long.MAX_VALUE : holdUntil;
            }
            boolean dcsMatch = dcsRawMatch;
            if (!dcsMatch && dcsGateEnabled && gateHoldNanos > 0L && nowNanos <= dcsGateHoldUntilNanos) {
                dcsMatch = true;
            }

            ctcssStatusDetector.consume(currentBuffer, n, processingFormat);
            Double detectedCtcssTone = ctcssStatusDetector.getDetectedToneHz();
            statusDetectedCtcssLabel = (detectedCtcssTone == null) ? null : formatCtcssTone(detectedCtcssTone.doubleValue());

            boolean ctcssRawMatch = !ctcssGateEnabled || ctcssGateDetector.consume(currentBuffer, n, processingFormat);
            if (ctcssGateEnabled && ctcssRawMatch && gateHoldNanos > 0L) {
                long holdUntil = nowNanos + gateHoldNanos;
                ctcssGateHoldUntilNanos = (holdUntil < 0L) ? Long.MAX_VALUE : holdUntil;
            }
            boolean ctcssMatch = ctcssRawMatch;
            if (!ctcssMatch && ctcssGateEnabled && gateHoldNanos > 0L && nowNanos <= ctcssGateHoldUntilNanos) {
                ctcssMatch = true;
            }
            if (dcsGateEnabled && dcsMatch != dcsGateOpen) {
                dcsGateOpen = dcsMatch;
                if (dcsGateOpen) {
                    log("DCS", ANSI_GREEN,
                            "DCS " + this.requiredDcsLabel + " detected ("
                                    + dcsGateDetector.getPolarityLabel() + "), gate open.");
                        publishEvent("dcsDetected",
                            "dcs", this.requiredDcsLabel,
                            "polarity", dcsGateDetector.getPolarityLabel());
                } else {
                    log("DCS", ANSI_YELLOW,
                            "DCS " + this.requiredDcsLabel + " no longer detected, gate closed.");
                    publishEvent("dcsGone",
                            "dcs", this.requiredDcsLabel,
                            "polarity", dcsGateDetector.getPolarityLabel());
                }
            }
            if (ctcssGateEnabled && ctcssMatch != ctcssGateOpen) {
                ctcssGateOpen = ctcssMatch;
                if (ctcssGateOpen) {
                    log("CTCSS", ANSI_GREEN,
                            "CTCSS " + this.requiredCtcssLabel + " detected, gate open.");
                        publishEvent("ctcssDetected",
                            "ctcss", this.requiredCtcssLabel);
                } else {
                    log("CTCSS", ANSI_YELLOW,
                            "CTCSS " + this.requiredCtcssLabel + " no longer detected, gate closed.");
                    publishEvent("ctcssGone",
                            "ctcss", this.requiredCtcssLabel);
                }
            }

            if (statusDcsGateOpen != dcsMatch) {
                statusDcsGateOpen = dcsMatch;
                statusDcsGateSinceNanos = nowNanos;
            }
            if (statusCtcssGateOpen != ctcssMatch) {
                statusCtcssGateOpen = ctcssMatch;
                statusCtcssGateSinceNanos = nowNanos;
            }
            RmsGate.Result rmsGateResult = this.rmsGate.evaluate(currentBuffer, n, processingFormat);
            double currentRmsDb = rmsGateResult.getRmsDb();
            boolean rmsGateOpen = rmsGateResult.isOpen();
            statusRmsDb = currentRmsDb;
            if (statusRmsGateOpen != rmsGateOpen) {
                statusRmsGateOpen = rmsGateOpen;
                statusRmsGateSinceNanos = nowNanos;
            }

            String currentGateBlockReason = determineGateBlockReason(dcsMatch, ctcssMatch, rmsGateOpen);
            boolean currentGateOpen = (currentGateBlockReason == null);

            if (statusGateOpen != currentGateOpen || !sameNullableText(statusGateReason, currentGateBlockReason)) {
                statusGateSinceNanos = nowNanos;
            }
            statusGateOpen = currentGateOpen;
            statusGateReason = currentGateBlockReason;

            boolean gateAllowsAudio = dcsMatch && ctcssMatch && rmsGateOpen;
            boolean includeFrameInClip = gateAllowsAudio || activelyRecording;
            byte[] frameForOutput = currentBuffer;
            if (includeFrameInClip && !gateAllowsAudio && activelyRecording) {
                frameForOutput = new byte[n];
            }
            if (includeFrameInClip) {
                double frameGainDb = this.outputGainDb;
                if (this.autoGainEnabled && gateAllowsAudio) {
                    double neededAutoGainDb = AUTO_GAIN_TARGET_DB - currentRmsDb;
                    double targetAutoGainDb = Math.max(0.0, Math.min(AUTO_GAIN_MAX_DB, neededAutoGainDb));
                    double bufferDurationSec = (n / (double) frameSize) / frameRate;
                    double delta = targetAutoGainDb - this.smoothedAutoGainDb;
                    if (delta > 0.0) {
                        this.smoothedAutoGainDb += Math.min(delta, AUTO_GAIN_ATTACK_DB_PER_SEC * bufferDurationSec);
                    } else {
                        this.smoothedAutoGainDb += Math.max(delta, -AUTO_GAIN_RELEASE_DB_PER_SEC * bufferDurationSec);
                    }
                    frameGainDb += this.smoothedAutoGainDb;
                }
                applyGainInPlace(currentBuffer, n, processingFormat, frameGainDb);
            }

            if (gateAllowsAudio) {
                statusOutputDb = this.rmsGate.evaluate(currentBuffer, n, processingFormat).getRmsDb();
            } else {
                statusOutputDb = -100.0;
            }
            if (statusAudioDetected != gateAllowsAudio) {
                statusAudioDetected = gateAllowsAudio;
                statusAudioDetectedSinceNanos = nowNanos;
            }
            nextStatusEmitNanos = publishStatusIfDue(
                    nowNanos,
                    nextStatusEmitNanos,
                    dcsGateEnabled,
                    statusDcsGateOpen,
                    statusDcsGateSinceNanos,
                    ctcssGateEnabled,
                    statusCtcssGateOpen,
                    statusCtcssGateSinceNanos,
                    statusRmsGateOpen,
                    statusRmsGateSinceNanos,
                    statusRmsDb,
                    statusOutputDb,
                    statusGateOpen,
                    statusGateReason,
                    statusGateSinceNanos,
                    statusAudioDetected,
                    statusAudioDetectedSinceNanos,
                    statusDetectedDcsLabel,
                    statusDetectedDcsPolarity,
                    statusDcsConfidence,
                    statusDetectedCtcssLabel,
                    this.streamUrl,
                    this.stdinMode,
                    processingFormat,
                    this.baseDir);
            if (this.stdoutEnabled && this.stdoutRawMode) {
                if (this.stdoutPadMode) {
                    byte[] stdoutFrame = includeFrameInClip
                            ? Arrays.copyOf(frameForOutput, n)
                            : new byte[n];
                    stdoutPadBuffer.addLast(stdoutFrame);
                    drainStdoutPadBuffer(stdoutPadBuffer, n, processingFormat);
                } else if (includeFrameInClip) {
                    writeStdoutRaw(frameForOutput, n, processingFormat);
                }
            }
            if (!pipeSessions.isEmpty() && this.pipeRawMode) {
                if (this.pipeRawPadMode) {
                    byte[] pipeFrame = includeFrameInClip
                            ? Arrays.copyOf(frameForOutput, n)
                            : new byte[n];
                    pipePadBuffer.addLast(pipeFrame);
                    drainPipePadBuffer(pipePadBuffer, n, processingFormat, pipeSessions);
                } else if (includeFrameInClip) {
                    writePipeRaw(pipeSessions, frameForOutput, n, processingFormat);
                }
            }
            if (deviceOutputSession != null && includeFrameInClip) {
                writeDeviceOutput(deviceOutputSession, frameForOutput, n, processingFormat);
            }

            if (gateAllowsAudio) {
                if (chunk.size() == 0) {
                    chunkStartTime = System.currentTimeMillis();
                    if (canWriteRecordings()) {
                        log("RECORD", ANSI_GREEN,
                                "Audio detected, starting clip for stream " + streamLabel + ".");
                    }
                    publishEvent("audioDetected");
                }
                chunk.write(frameForOutput, 0, n);
                recordedFrames += n / frameSize;
                soundFrames += n / frameSize;
                silentFrames = 0;
                activelyRecording = true;
            } else {
                if (activelyRecording) {
                    chunk.write(frameForOutput, 0, n);
                    recordedFrames += n / frameSize;
                }
                silentFrames += n / frameSize;
                if (silentFrames >= framesForSilence && chunk.size() > 0) {
                    if (canWriteRecordings()) {
                    log("SILENCE", ANSI_YELLOW,
                        String.format("Silence reached after %.1f s, closing clip.",
                            recordedFrames / frameRate));
                    }
                    publishEvent("silenceDetected");
                    byte[] clipAudio = chunk.toByteArray();
                    double soundSeconds = soundFrames / frameRate;
                    if (soundSeconds > 1.0) { // only keep clips that have at least 1 second of sound
                        if (canWriteRecordings()) {
                            writeChunk(clipAudio, processingFormat, clipOutputFormat, chunkStartTime);
                        }
                        if (this.stdoutEnabled && !this.stdoutRawMode) {
                            writeChunkToStdoutWav(clipAudio, processingFormat, clipOutputFormat);
                        }
                        if (!pipeSessions.isEmpty() && !this.pipeRawMode) {
                            writeChunkToPipeWav(pipeSessions, clipAudio, processingFormat, clipOutputFormat);
                        }
                    } else {
                        if (canWriteRecordings()) {
                            log("RECORD", ANSI_YELLOW,
                                    String.format("Discarding clip with only %.1f s of sound.",
                                            soundSeconds));
                        }
                    }
                    chunk.reset();
                    activelyRecording = false;
                    recordedFrames = 0;
                    soundFrames = 0;
                    silentFrames = 0;
                }
            }
        }
            if (chunk.size() > 0) {
            byte[] clipAudio = chunk.toByteArray();
            double soundSeconds = soundFrames / frameRate;
            if (soundSeconds > 1.0) {
                if (canWriteRecordings()) {
                    writeChunk(clipAudio, processingFormat, clipOutputFormat, chunkStartTime);
                }
                if (this.stdoutEnabled && !this.stdoutRawMode) {
                    writeChunkToStdoutWav(clipAudio, processingFormat, clipOutputFormat);
                }
                if (!pipeSessions.isEmpty() && !this.pipeRawMode) {
                    writeChunkToPipeWav(pipeSessions, clipAudio, processingFormat, clipOutputFormat);
                }
            } else {
                if (canWriteRecordings()) {
                    log("RECORD", ANSI_YELLOW,
                            String.format("Discarding clip with only %.1f s of sound.",
                                    soundSeconds));
                }
            }
            chunk.reset();
        }
        } finally {
            closeDeviceOutputSession(deviceOutputSession);
            closePipeOutputSessions(pipeSessions);
        }
    }

    private void logDcsConfiguration() {
        if (this.requiredDcsCode == null) {
            return;
        }

        String message = "DCS gate enabled for code " + this.requiredDcsLabel
                + " (normal and inverted polarity, " + DcsDetector.BITRATE + " bps). Clips open only on matching DCS.";
        if (this.gateHoldSeconds > 0.0) {
            message += String.format(" Gate hold adds %.3f s of grace after decode drop.", this.gateHoldSeconds);
        }
        log("DCS", ANSI_CYAN, message);
    }

    private void logCtcssConfiguration() {
        if (this.requiredCtcssToneHz == null) {
            return;
        }

        String message = "CTCSS gate enabled for tone " + this.requiredCtcssLabel
                + " Hz. Clips open only on matching tone.";
        if (this.gateHoldSeconds > 0.0) {
            message += String.format(" Gate hold adds %.3f s of grace after decode drop.", this.gateHoldSeconds);
        }
        log("CTCSS", ANSI_CYAN, message);
    }

    private void logStdoutConfiguration(AudioFormat activeFormat) {
        if (!this.stdoutEnabled || this.stdoutConfigLogged) {
            return;
        }

        if (this.stdoutRawMode) {
            AudioFormat targetFormat = (this.stdoutRawFormat == null) ? activeFormat : this.stdoutRawFormat;
            log("STDOUT", ANSI_CYAN,
                    "stdout output enabled (raw PCM): " + describeAudioFormat(targetFormat));
            if (this.stdoutPadMode) {
                log("STDOUT", ANSI_CYAN,
                        "stdout pad enabled: " + this.stdoutPadDelayMillis
                                + " ms delay buffer, continuous silence on stall.");
            }
        } else {
            log("STDOUT", ANSI_CYAN,
                    "stdout output enabled (WAV clip stream).");
        }

        if (!canWriteRecordings()) {
            log("WRITE", ANSI_YELLOW,
                    "File recording disabled (no -o provided while using --stdout). ");
        }

        this.stdoutConfigLogged = true;
    }

    private void logDeviceConfiguration(AudioFormat activeFormat) {
        if (!this.deviceOutputEnabled || this.deviceConfigLogged) {
            return;
        }

        String selector = this.deviceOutputSelector;
        if (selector == null) {
            selector = "default";
        }
        log("DEV", ANSI_CYAN,
                "hardware audio output enabled: selector='" + selector
                        + "', preferred format " + describeAudioFormat(activeFormat));
        this.deviceConfigLogged = true;
    }

    private void logPipeConfiguration(AudioFormat activeFormat) {
        if (this.pipeOutputCommands.isEmpty() || this.pipeConfigLogged) {
            return;
        }

        for (String command : this.pipeOutputCommands) {
            log("PIPE", ANSI_CYAN,
                    "pipe output enabled: " + command);
        }
        if (this.pipeRawMode) {
            AudioFormat targetFormat = (this.pipeRawFormat == null) ? activeFormat : this.pipeRawFormat;
            log("PIPE", ANSI_CYAN,
                "pipe mode: raw PCM stream: " + describeAudioFormat(targetFormat));
            if (this.pipeRawPadMode) {
                log("PIPE", ANSI_CYAN,
                        "pipe pad enabled: " + this.pipeRawPadDelayMillis
                                + " ms delay buffer, continuous silence on stall.");
            }
        } else {
            log("PIPE", ANSI_CYAN,
                    "pipe mode: WAV clip stream (one WAV payload per closed clip).");
        }
        this.pipeConfigLogged = true;
    }

    private void logGainConfiguration() {
        if (Math.abs(this.outputGainDb) > 0.001) {
            log("GAIN", ANSI_CYAN,
                    String.format(Locale.US,
                            "Manual post-gate gain enabled: %+.1f dB.",
                            this.outputGainDb));
        }
        if (this.autoGainEnabled) {
            log("GAIN", ANSI_CYAN,
                    String.format(Locale.US,
                            "Auto-gain enabled: target %.1f dB, max boost %.1f dB.",
                            AUTO_GAIN_TARGET_DB,
                            AUTO_GAIN_MAX_DB));
        }
    }

    private static void applyGainInPlace(byte[] data, int len, AudioFormat format, double gainDb) {
        if (len <= 0 || Math.abs(gainDb) <= 0.0001) {
            return;
        }
        if (format.getSampleSizeInBits() != 16) {
            return;
        }

        boolean bigEndian = format.isBigEndian();
        double gain = Math.pow(10.0, gainDb / 20.0);
        for (int offset = 0; offset + 1 < len; offset += 2) {
            int sample;
            if (bigEndian) {
                int hi = data[offset];
                int lo = data[offset + 1] & 0xff;
                sample = (hi << 8) | lo;
            } else {
                int lo = data[offset] & 0xff;
                int hi = data[offset + 1];
                sample = (hi << 8) | lo;
            }

            int amplified = (int) Math.round(sample * gain);
            if (amplified > 32767) {
                amplified = 32767;
            } else if (amplified < -32768) {
                amplified = -32768;
            }

            if (bigEndian) {
                data[offset] = (byte) ((amplified >>> 8) & 0xFF);
                data[offset + 1] = (byte) (amplified & 0xFF);
            } else {
                data[offset] = (byte) (amplified & 0xFF);
                data[offset + 1] = (byte) ((amplified >>> 8) & 0xFF);
            }
        }
    }

    private void writeChunkToStdoutWav(byte[] audioData, AudioFormat sourceFormat, AudioFormat targetFormat) {
        try (AudioInputStream ais = openClipStream(audioData, sourceFormat, targetFormat)) {
            synchronized (System.out) {
                AudioSystem.write(ais, AudioFileFormat.Type.WAVE, System.out);
                System.out.flush();
            }
        } catch (IOException ioe) {
            logError("Failed to write WAV clip to stdout: " + ioe.getMessage());
        } catch (UnsupportedAudioFileException uafe) {
            logError("Failed to convert WAV clip for stdout: " + uafe.getMessage());
        }
    }

    private void drainStdoutPadBuffer(ArrayDeque<byte[]> buffer, int bytes, AudioFormat format) throws IOException {
        int remaining = bytes;
        while (remaining > 0 && !buffer.isEmpty()) {
            byte[] head = buffer.removeFirst();
            if (head.length <= remaining) {
                writeStdoutRaw(head, head.length, format);
                remaining -= head.length;
            } else {
                writeStdoutRaw(head, remaining, format);
                buffer.addFirst(Arrays.copyOfRange(head, remaining, head.length));
                remaining = 0;
            }
        }
    }

    private void drainPipePadBuffer(ArrayDeque<byte[]> buffer,
                                    int bytes,
                                    AudioFormat format,
                                    List<PipeOutputSession> sessions) {
        int remaining = bytes;
        while (remaining > 0 && !buffer.isEmpty()) {
            byte[] head = buffer.removeFirst();
            if (head.length <= remaining) {
                writePipeRaw(sessions, head, head.length, format);
                remaining -= head.length;
            } else {
                writePipeRaw(sessions, head, remaining, format);
                buffer.addFirst(Arrays.copyOfRange(head, remaining, head.length));
                remaining = 0;
            }
        }
    }

    private void writeStdoutRaw(byte[] audioData, int len, AudioFormat sourceFormat) throws IOException {
        if (len <= 0 || !this.stdoutEnabled || !this.stdoutRawMode) {
            return;
        }

        AudioFormat targetFormat = (this.stdoutRawFormat == null) ? sourceFormat : this.stdoutRawFormat;

        if (audioFormatsEquivalent(sourceFormat, targetFormat)) {
            synchronized (System.out) {
                System.out.write(audioData, 0, len);
                System.out.flush();
            }
            return;
        }

        if (!AudioSystem.isConversionSupported(targetFormat, sourceFormat)) {
            if (!this.stdoutRawConversionWarned) {
                this.stdoutRawConversionWarned = true;
                log("STDOUT", ANSI_YELLOW,
                        "stdout raw conversion unsupported from "
                                + describeAudioFormat(sourceFormat)
                                + " to "
                                + describeAudioFormat(targetFormat)
                                + "; writing source format bytes instead.");
            }
            synchronized (System.out) {
                System.out.write(audioData, 0, len);
                System.out.flush();
            }
            return;
        }

        try (ByteArrayInputStream bais = new ByteArrayInputStream(audioData, 0, len);
             AudioInputStream source = new AudioInputStream(bais, sourceFormat, len / sourceFormat.getFrameSize());
             AudioInputStream converted = AudioSystem.getAudioInputStream(targetFormat, source)) {
            byte[] convertedBuffer = new byte[Math.max(1024, targetFormat.getFrameSize() * 256)];
            int read;
            synchronized (System.out) {
                while ((read = converted.read(convertedBuffer)) != -1) {
                    System.out.write(convertedBuffer, 0, read);
                }
                System.out.flush();
            }
        } catch (Exception conversionException) {
            if (!this.stdoutRawConversionWarned) {
                this.stdoutRawConversionWarned = true;
                log("STDOUT", ANSI_YELLOW,
                        "stdout raw conversion failed (" + conversionException.getMessage()
                                + "); writing source format bytes instead.");
            }
            synchronized (System.out) {
                System.out.write(audioData, 0, len);
                System.out.flush();
            }
        }
    }

    private List<PipeOutputSession> openPipeOutputSessions() {
        List<PipeOutputSession> sessions = new ArrayList<>();
        for (String command : this.pipeOutputCommands) {
            PipeOutputSession session = new PipeOutputSession(command);
            if (!restartPipeSession(session, "startup")) {
                session.nextRestartAttemptAtMillis = System.currentTimeMillis() + PIPE_RESTART_BACKOFF_MILLIS;
            }
            sessions.add(session);
        }
        return sessions;
    }

    private PipeOutputSession openPipeOutputSession(String command) throws IOException {
        ProcessBuilder pb = new ProcessBuilder(buildShellCommand(command));
        pb.redirectErrorStream(true);
        Process process = pb.start();

        Thread outputDrainer = new Thread(() -> {
            try (BufferedReader outputReader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
                String line;
                while ((line = outputReader.readLine()) != null) {
                    log("PIPE", ANSI_BLUE, "[" + command + "] " + line);
                }
            } catch (IOException ignored) {
                // Process stream closed.
            }
        }, "pipe-output-" + Math.abs(command.hashCode()));
        outputDrainer.setDaemon(true);
        outputDrainer.start();

        PipeOutputSession session = new PipeOutputSession(command);
        session.process = process;
        session.stdin = process.getOutputStream();
        session.broken = false;
        session.nextRestartAttemptAtMillis = 0L;
        return session;
    }

    private void closePipeOutputSessions(List<PipeOutputSession> sessions) {
        if (sessions == null || sessions.isEmpty()) {
            return;
        }

        for (PipeOutputSession session : sessions) {
            try {
                if (session.stdin != null) {
                    session.stdin.close();
                }
            } catch (IOException ignored) {
                // Best effort during shutdown.
            }
            try {
                if (session.process != null && !session.process.waitFor(1500L, TimeUnit.MILLISECONDS)) {
                    session.process.destroy();
                }
            } catch (InterruptedException interruptedException) {
                Thread.currentThread().interrupt();
                if (session.process != null) {
                    session.process.destroy();
                }
            }
            session.process = null;
            session.stdin = null;
            session.broken = true;
        }
    }

    private void writeChunkToPipeWav(List<PipeOutputSession> sessions,
                                     byte[] audioData,
                                     AudioFormat sourceFormat,
                                     AudioFormat targetFormat) {
        if (sessions == null || sessions.isEmpty()) {
            return;
        }

        final byte[] wavBytes;
        try {
            wavBytes = buildWavBytes(audioData, sourceFormat, targetFormat);
        } catch (Exception conversionException) {
            logError("Failed to convert clip for pipe output: " + conversionException.getMessage());
            return;
        }

        for (PipeOutputSession session : sessions) {
            writePipeBytes(session, wavBytes, 0, wavBytes.length, "WAV clip");
        }
    }

    private void writePipeRaw(List<PipeOutputSession> sessions,
                              byte[] audioData,
                              int len,
                              AudioFormat sourceFormat) {
        if (sessions == null || sessions.isEmpty() || len <= 0) {
            return;
        }

        AudioFormat targetFormat = (this.pipeRawFormat == null) ? sourceFormat : this.pipeRawFormat;

        if (audioFormatsEquivalent(sourceFormat, targetFormat)) {
            for (PipeOutputSession session : sessions) {
                writePipeBytes(session, audioData, 0, len, "raw PCM");
            }
            return;
        }

        if (!AudioSystem.isConversionSupported(targetFormat, sourceFormat)) {
            if (!this.pipeRawConversionWarned) {
                this.pipeRawConversionWarned = true;
                log("PIPE", ANSI_YELLOW,
                        "pipe raw conversion unsupported from "
                                + describeAudioFormat(sourceFormat)
                                + " to "
                                + describeAudioFormat(targetFormat)
                                + "; dropping raw pipe audio.");
            }
            return;
        }

        try (ByteArrayInputStream bais = new ByteArrayInputStream(audioData, 0, len);
             AudioInputStream source = new AudioInputStream(bais, sourceFormat, len / sourceFormat.getFrameSize());
             AudioInputStream converted = AudioSystem.getAudioInputStream(targetFormat, source);
             ByteArrayOutputStream convertedOut = new ByteArrayOutputStream()) {
            byte[] convertedBuffer = new byte[Math.max(1024, targetFormat.getFrameSize() * 256)];
            int read;
            while ((read = converted.read(convertedBuffer)) != -1) {
                convertedOut.write(convertedBuffer, 0, read);
            }
            byte[] convertedBytes = convertedOut.toByteArray();
            for (PipeOutputSession session : sessions) {
                writePipeBytes(session, convertedBytes, 0, convertedBytes.length, "raw PCM");
            }
        } catch (Exception conversionException) {
            if (!this.pipeRawConversionWarned) {
                this.pipeRawConversionWarned = true;
                log("PIPE", ANSI_YELLOW,
                        "pipe raw conversion failed (" + conversionException.getMessage() + "); dropping raw pipe audio.");
            }
        }
    }

    private byte[] buildWavBytes(byte[] audioData, AudioFormat sourceFormat, AudioFormat targetFormat)
            throws IOException, UnsupportedAudioFileException {
        try (AudioInputStream ais = openClipStream(audioData, sourceFormat, targetFormat);
             ByteArrayOutputStream output = new ByteArrayOutputStream(Math.max(4096, audioData.length + 64))) {
            AudioSystem.write(ais, AudioFileFormat.Type.WAVE, output);
            return output.toByteArray();
        }
    }

    private boolean writePipeBytes(PipeOutputSession session,
                                   byte[] data,
                                   int off,
                                   int len,
                                   String modeDescription) {
        if (session == null) {
            return false;
        }
        if (!ensurePipeSessionReady(session, modeDescription)) {
            return false;
        }

        try {
            session.stdin.write(data, off, len);
            session.stdin.flush();
            return true;
        } catch (IOException ioe) {
            log("PIPE", ANSI_YELLOW,
                    "Pipe command closed (" + modeDescription + "): " + session.command
                            + " (" + ioe.getMessage() + "), scheduling restart.");
            markPipeSessionFailed(session);
            return false;
        }
    }

    private boolean ensurePipeSessionReady(PipeOutputSession session, String modeDescription) {
        if (session == null) {
            return false;
        }

        Process process = session.process;
        if (process != null && !process.isAlive()) {
            markPipeSessionFailed(session);
            log("PIPE", ANSI_YELLOW,
                    "Pipe command exited, scheduling restart (" + modeDescription + "): " + session.command);
        }

        if (session.process != null && session.stdin != null && !session.broken) {
            return true;
        }

        long now = System.currentTimeMillis();
        if (now < session.nextRestartAttemptAtMillis) {
            return false;
        }

        return restartPipeSession(session, modeDescription);
    }

    private boolean restartPipeSession(PipeOutputSession session, String reason) {
        if (session == null) {
            return false;
        }
        try {
            PipeOutputSession restarted = openPipeOutputSession(session.command);
            session.process = restarted.process;
            session.stdin = restarted.stdin;
            session.broken = false;
            session.nextRestartAttemptAtMillis = 0L;
            log("PIPE", ANSI_CYAN,
                    "Pipe command running (" + reason + "): " + session.command);
            return true;
        } catch (IOException ioe) {
            session.process = null;
            session.stdin = null;
            session.broken = true;
            session.nextRestartAttemptAtMillis = System.currentTimeMillis() + PIPE_RESTART_BACKOFF_MILLIS;
            log("PIPE", ANSI_YELLOW,
                    "Failed to start pipe command: " + session.command + " (" + ioe.getMessage() + ")");
            return false;
        }
    }

    private void markPipeSessionFailed(PipeOutputSession session) {
        if (session == null) {
            return;
        }
        session.broken = true;
        session.nextRestartAttemptAtMillis = System.currentTimeMillis() + PIPE_RESTART_BACKOFF_MILLIS;
        if (session.stdin != null) {
            try {
                session.stdin.close();
            } catch (IOException ignored) {
                // Best effort.
            }
        }
        if (session.process != null) {
            session.process.destroy();
        }
        session.stdin = null;
        session.process = null;
    }

    private static List<String> buildShellCommand(String command) {
        String osName = System.getProperty("os.name", "").toLowerCase(Locale.ROOT);
        if (osName.contains("win")) {
            return Arrays.asList("cmd.exe", "/c", command);
        }
        return Arrays.asList("/bin/sh", "-c", command);
    }

    private DeviceOutputSession openDeviceOutputSession(AudioFormat sourceFormat, AudioFormat preferredFormat) throws IOException {
        String selector = this.deviceOutputSelector;
        Mixer.Info mixerInfo = resolveOutputMixerInfo(selector);
        Mixer mixer = AudioSystem.getMixer(mixerInfo);

        AudioFormat lineFormat = preferredFormat;
        if (!isMixerSourceLineSupported(mixer, lineFormat) && isMixerSourceLineSupported(mixer, sourceFormat)) {
            lineFormat = sourceFormat;
            log("DEV", ANSI_YELLOW,
                    "device does not support preferred format; using "
                            + describeAudioFormat(sourceFormat));
        }

        DataLine.Info lineInfo = new DataLine.Info(SourceDataLine.class, lineFormat);
        if (!mixer.isLineSupported(lineInfo)) {
            throw new IOException("Selected output device does not support playback format: "
                    + describeAudioFormat(lineFormat));
        }

        try {
            SourceDataLine line = (SourceDataLine) mixer.getLine(lineInfo);
            line.open(lineFormat);
            line.start();
            log("DEV", ANSI_CYAN,
                    "playing on " + describeMixer(mixerInfo)
                            + " at " + describeAudioFormat(lineFormat));
            return new DeviceOutputSession(line, lineFormat, mixerInfo);
        } catch (LineUnavailableException lue) {
            throw new IOException("Unable to open selected output device: " + lue.getMessage(), lue);
        }
    }

    private void closeDeviceOutputSession(DeviceOutputSession session) {
        if (session == null || session.line == null) {
            return;
        }

        try {
            session.line.drain();
        } catch (Exception ignored) {
            // Best effort during shutdown.
        }
        try {
            session.line.stop();
        } catch (Exception ignored) {
            // Best effort during shutdown.
        }
        try {
            session.line.close();
        } catch (Exception ignored) {
            // Best effort during shutdown.
        }
    }

    private void writeDeviceOutput(DeviceOutputSession session, byte[] audioData, int len, AudioFormat sourceFormat) {
        if (session == null || len <= 0) {
            return;
        }

        AudioFormat targetFormat = session.format;
        try {
            if (audioFormatsEquivalent(sourceFormat, targetFormat)) {
                session.line.write(audioData, 0, len);
                return;
            }

            if (!AudioSystem.isConversionSupported(targetFormat, sourceFormat)) {
                if (!this.deviceOutputConversionWarned) {
                    this.deviceOutputConversionWarned = true;
                    log("DEV", ANSI_YELLOW,
                            "device conversion unsupported from "
                                    + describeAudioFormat(sourceFormat)
                                    + " to "
                                    + describeAudioFormat(targetFormat)
                                    + "; dropping device audio.");
                }
                return;
            }

            try (ByteArrayInputStream bais = new ByteArrayInputStream(audioData, 0, len);
                 AudioInputStream source = new AudioInputStream(bais, sourceFormat, len / sourceFormat.getFrameSize());
                 AudioInputStream converted = AudioSystem.getAudioInputStream(targetFormat, source)) {
                byte[] convertedBuffer = new byte[Math.max(1024, targetFormat.getFrameSize() * 256)];
                int read;
                while ((read = converted.read(convertedBuffer)) != -1) {
                    session.line.write(convertedBuffer, 0, read);
                }
            }
        } catch (Exception deviceWriteException) {
            if (!this.deviceOutputConversionWarned) {
                this.deviceOutputConversionWarned = true;
                log("DEV", ANSI_YELLOW,
                        "device playback failed (" + deviceWriteException.getMessage()
                                + "); disabling further conversion warnings.");
            }
        }
    }

    private static boolean isMixerSourceLineSupported(Mixer mixer, AudioFormat format) {
        if (mixer == null || format == null) {
            return false;
        }
        DataLine.Info lineInfo = new DataLine.Info(SourceDataLine.class, format);
        return mixer.isLineSupported(lineInfo);
    }

    private static Mixer.Info resolveOutputMixerInfo(String selector) throws IOException {
        if (isBlank(selector)) {
            throw new IOException("Audio device selector is missing; use --devs to list devices");
        }

        Mixer.Info[] mixers = AudioSystem.getMixerInfo();
        Integer index = parseInteger(selector);
        if (index != null) {
            if (index < 0 || index >= mixers.length) {
                throw new IOException("Audio device index out of range: " + selector);
            }
            Mixer mixer = AudioSystem.getMixer(mixers[index]);
            DataLine.Info lineInfo = new DataLine.Info(SourceDataLine.class, null);
            if (!mixer.isLineSupported(lineInfo)) {
                throw new IOException("Audio device index " + selector + " is not an output playback device");
            }
            return mixers[index];
        }

        Mixer.Info exactMatch = null;
        Mixer.Info partialMatch = null;
        String wanted = selector.trim().toLowerCase(Locale.ROOT);
        DataLine.Info playbackLine = new DataLine.Info(SourceDataLine.class, null);
        for (Mixer.Info info : mixers) {
            Mixer mixer = AudioSystem.getMixer(info);
            if (!mixer.isLineSupported(playbackLine)) {
                continue;
            }

            String name = info.getName();
            if (name != null && name.equalsIgnoreCase(selector.trim())) {
                exactMatch = info;
                break;
            }

            String normalizedName = (name == null) ? "" : name.toLowerCase(Locale.ROOT);
            String normalizedDescription = (info.getDescription() == null)
                    ? ""
                    : info.getDescription().toLowerCase(Locale.ROOT);
            if (partialMatch == null
                    && (normalizedName.contains(wanted) || normalizedDescription.contains(wanted))) {
                partialMatch = info;
            }
        }

        if (exactMatch != null) {
            return exactMatch;
        }
        if (partialMatch != null) {
            return partialMatch;
        }
        throw new IOException("No matching output device for selector '" + selector + "'; use --devs to list devices");
    }

    private static Integer parseInteger(String value) {
        if (isBlank(value)) {
            return null;
        }
        String candidate = value.trim();
        if (!candidate.matches("-?\\d+")) {
            return null;
        }
        try {
            return Integer.parseInt(candidate);
        } catch (NumberFormatException numberFormatException) {
            return null;
        }
    }

    private static String describeMixer(Mixer.Info info) {
        if (info == null) {
            return "default mixer";
        }

        String description = info.getDescription();
        if (isBlank(description)) {
            return info.getName();
        }
        return info.getName() + " (" + description + ")";
    }

    private static boolean audioFormatsEquivalent(AudioFormat first, AudioFormat second) {
        if (first == second) {
            return true;
        }
        if (first == null || second == null) {
            return false;
        }
        return first.getEncoding().equals(second.getEncoding())
                && Float.compare(first.getSampleRate(), second.getSampleRate()) == 0
                && first.getSampleSizeInBits() == second.getSampleSizeInBits()
                && first.getChannels() == second.getChannels()
                && first.isBigEndian() == second.isBigEndian();
    }

    private static String describeAudioFormat(AudioFormat format) {
        if (format == null) {
            return "unknown format";
        }
        return String.format("%.0f Hz, %d channel(s), %d-bit %s, %s-endian",
                format.getSampleRate(),
                format.getChannels(),
                format.getSampleSizeInBits(),
                format.getEncoding(),
                format.isBigEndian() ? "big" : "little");
    }

    private void writeChunk(byte[] audioData, AudioFormat sourceFormat, AudioFormat targetFormat, long startTimeMs) {
        if (!canWriteRecordings()) {
            return;
        }

        try {
            Instant instant = Instant.ofEpochMilli(startTimeMs);
            LocalDate date = instant.atZone(ZoneId.systemDefault()).toLocalDate();
            LocalTime time = instant.atZone(ZoneId.systemDefault()).toLocalTime();
            String formattedDate = date.format(DATE_FMT);
            Path dateDir = baseDir.resolve(formattedDate);
            Files.createDirectories(dateDir);
            String name = formattedDate + "_" + time.format(TIME_FMT) + "_" + streamLabel + ".wav";
            Path out = dateDir.resolve(name);
            try (AudioInputStream ais = openClipStream(audioData, sourceFormat, targetFormat)) {
                AudioSystem.write(ais, AudioFileFormat.Type.WAVE, out.toFile());
            }
            try {
                appendWavInfoMetadata(out);
            } catch (IOException metadataException) {
                log("WRITE", ANSI_YELLOW,
                        "Saved clip but could not append WAV metadata: " + metadataException.getMessage());
            }
            publishEvent("savedRecording",
                    "filename", out.toAbsolutePath().normalize().toString());
            log("WRITE", ANSI_GREEN, "Saved clip: " + out);
            runOnWriteProgram(out.toAbsolutePath());
        } catch (UnsupportedAudioFileException uafe) {
            logError("Failed to convert chunk for output: " + uafe.getMessage());
        } catch (IOException ioe) {
            logError("Failed to write chunk: " + ioe.getMessage());
            ioe.printStackTrace(System.err);
        }
    }

    private AudioInputStream openClipStream(byte[] audioData,
                                            AudioFormat sourceFormat,
                                            AudioFormat targetFormat) throws UnsupportedAudioFileException, IOException {
        ByteArrayInputStream bais = new ByteArrayInputStream(audioData);
        AudioInputStream source = new AudioInputStream(bais, sourceFormat, audioData.length / sourceFormat.getFrameSize());

        if (audioFormatsEquivalent(sourceFormat, targetFormat)) {
            return source;
        }

        if (!AudioSystem.isConversionSupported(targetFormat, sourceFormat)) {
            source.close();
            throw new UnsupportedAudioFileException("unsupported conversion from "
                    + describeAudioFormat(sourceFormat)
                    + " to "
                    + describeAudioFormat(targetFormat));
        }

        try {
            return AudioSystem.getAudioInputStream(targetFormat, source);
        } catch (Exception conversionException) {
            source.close();
            if (conversionException instanceof UnsupportedAudioFileException) {
                throw (UnsupportedAudioFileException) conversionException;
            }
            if (conversionException instanceof IOException) {
                throw (IOException) conversionException;
            }
            UnsupportedAudioFileException wrapped = new UnsupportedAudioFileException(conversionException.getMessage());
            wrapped.initCause(conversionException);
            throw wrapped;
        }
    }

    private void appendWavInfoMetadata(Path wavPath) throws IOException {
        String sourceComment = (this.streamUrl == null) ? null : "Source URL: " + this.streamUrl;
        byte[] listChunk = buildInfoMetadataChunk(this.streamTitle, sourceComment);
        if (listChunk.length == 0) {
            return;
        }

        try (RandomAccessFile wavFile = new RandomAccessFile(wavPath.toFile(), "rw")) {
            if (!isRiffWaveFile(wavFile)) {
                throw new IOException("output file is not RIFF/WAVE");
            }

            long originalLength = wavFile.length();
            long newLength = originalLength + listChunk.length;
            long newRiffSize = newLength - 8;
            if (newRiffSize > 0xFFFFFFFFL) {
                throw new IOException("WAV file exceeds RIFF size limit after metadata append");
            }

            wavFile.seek(originalLength);
            wavFile.write(listChunk);
            wavFile.seek(4);
            writeLittleEndianInt(wavFile, (int) newRiffSize);
        }
    }

    private static byte[] buildInfoMetadataChunk(String title, String comment) throws IOException {
        ByteArrayOutputStream infoBody = new ByteArrayOutputStream();
        appendInfoSubChunk(infoBody, "INAM", title);
        appendInfoSubChunk(infoBody, "ICMT", comment);

        byte[] infoBodyBytes = infoBody.toByteArray();
        if (infoBodyBytes.length == 0) {
            return new byte[0];
        }

        int listChunkSize = 4 + infoBodyBytes.length;
        ByteBuffer buffer = ByteBuffer.allocate(8 + listChunkSize).order(ByteOrder.LITTLE_ENDIAN);
        buffer.put("LIST".getBytes(StandardCharsets.US_ASCII));
        buffer.putInt(listChunkSize);
        buffer.put("INFO".getBytes(StandardCharsets.US_ASCII));
        buffer.put(infoBodyBytes);
        return buffer.array();
    }

    private static void appendInfoSubChunk(ByteArrayOutputStream infoBody,
                                           String chunkId,
                                           String value) throws IOException {
        if (isBlank(value)) {
            return;
        }

        byte[] textData = value.getBytes(StandardCharsets.UTF_8);
        byte[] chunkData = Arrays.copyOf(textData, textData.length + 1);
        infoBody.write(chunkId.getBytes(StandardCharsets.US_ASCII));
        writeLittleEndianInt(infoBody, chunkData.length);
        infoBody.write(chunkData);
        if ((chunkData.length % 2) != 0) {
            infoBody.write(0);
        }
    }

    private static boolean isRiffWaveFile(RandomAccessFile wavFile) throws IOException {
        if (wavFile.length() < 12) {
            return false;
        }

        wavFile.seek(0);
        byte[] riff = new byte[4];
        wavFile.readFully(riff);
        wavFile.seek(8);
        byte[] wave = new byte[4];
        wavFile.readFully(wave);
        return Arrays.equals(riff, "RIFF".getBytes(StandardCharsets.US_ASCII))
                && Arrays.equals(wave, "WAVE".getBytes(StandardCharsets.US_ASCII));
    }

    private static void writeLittleEndianInt(RandomAccessFile file, int value) throws IOException {
        file.write(value & 0xFF);
        file.write((value >>> 8) & 0xFF);
        file.write((value >>> 16) & 0xFF);
        file.write((value >>> 24) & 0xFF);
    }

    private static void writeLittleEndianInt(OutputStream out, int value) throws IOException {
        out.write(value & 0xFF);
        out.write((value >>> 8) & 0xFF);
        out.write((value >>> 16) & 0xFF);
        out.write((value >>> 24) & 0xFF);
    }

    private void runOnWriteProgram(Path wavPath) {
        if (!canWriteRecordings()) {
            return;
        }

        if (this.onWriteCommand == null || this.onWriteCommand.isEmpty()) {
            return;
        }

        final String wavFile = wavPath.toString();
        Thread hookThread = new Thread(() -> {
            try {
                List<String> command = buildHookCommand(wavFile);
                publishEvent("hookExecuted", "command", String.join(" ", command));
                ProcessBuilder pb = new ProcessBuilder(command);
                pb.redirectErrorStream(true);
                Process process = pb.start();

                try (BufferedReader outputReader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
                    String line;
                    while ((line = outputReader.readLine()) != null) {
                        log("HOOK", ANSI_BLUE, line);
                        publishEvent("hookResult", "result", line);
                    }
                }

                int exitCode = process.waitFor();
                if (exitCode == 0) {
                    log("HOOK", ANSI_GREEN, "Completed for " + wavFile);
                } else {
                    log("HOOK", ANSI_YELLOW,
                            "Exited with code " + exitCode + " for " + wavFile);
                }
                publishEvent("hookResult", "result", "exitCode=" + exitCode);
            } catch (Exception e) {
                logError("on-write execution failed for " + wavFile + ": " + e.getMessage());
                publishEvent("hookResult", "result", "error: " + e.getMessage());
            }
        }, "on-write-hook");
        hookThread.setDaemon(true);
        hookThread.start();
    }

    private List<String> buildHookCommand(String wavFile) {
        List<String> command = new ArrayList<>();
        String firstArg = this.onWriteCommand.get(0);
        if (firstArg.matches("^/mnt/[a-z]/.*") && (firstArg.toLowerCase().endsWith(".exe") || firstArg.toLowerCase().endsWith(".bat") || firstArg.toLowerCase().endsWith(".cmd"))) {
            wavFile = wavFile.substring(5,6).toUpperCase() + ":\\" + wavFile.substring(7).replaceAll(Pattern.quote("/./"),"/").replace('/','\\');
            log("HOOK", ANSI_CYAN, "WSL Path Translated " + wavFile);
        }
        command.add(firstArg);

        boolean hasPlaceholder = false;
        for (int i = 1; i < this.onWriteCommand.size(); i++) {
            String token = this.onWriteCommand.get(i);
            if ("{wav}".equals(token)) {
                command.add(wavFile);
                hasPlaceholder = true;
            } else {
                command.add(token);
            }
        }

        if (!hasPlaceholder) {
            // Default behavior keeps WAV path as argument 1 to the target program.
            command.add(1, wavFile);
        }

        return command;
    }

    private void logHookConfiguration() {
        if (this.onWriteCommand == null || this.onWriteCommand.isEmpty()) {
            return;
        }

        if (!canWriteRecordings()) {
            log("HOOK", ANSI_YELLOW,
                    "on-write ignored because file recording is disabled (no output directory configured).");
            return;
        }

        boolean hasPlaceholder = false;
        for (String token : this.onWriteCommand) {
            if ("{wav}".equals(token)) {
                hasPlaceholder = true;
                break;
            }
        }

        if (hasPlaceholder) {
            log("HOOK", ANSI_CYAN, "on-write enabled (mode=placeholder): " + String.join(" ", this.onWriteCommand));
        } else {
            log("HOOK", ANSI_CYAN, "on-write enabled (mode=arg1): " + String.join(" ", this.onWriteCommand));
        }
    }

    private static List<String> parseCommandLine(String commandLine) {
        if (commandLine == null) {
            return null;
        }
        String source = commandLine.trim();
        if (source.isEmpty()) {
            return null;
        }

        List<String> tokens = new ArrayList<>();
        StringBuilder current = new StringBuilder();
        boolean inSingleQuote = false;
        boolean inDoubleQuote = false;
        boolean escaping = false;

        for (int i = 0; i < source.length(); i++) {
            char ch = source.charAt(i);

            if (escaping) {
                current.append(ch);
                escaping = false;
                continue;
            }

            if (ch == '\\') {
                escaping = true;
                continue;
            }

            if (ch == '\'' && !inDoubleQuote) {
                inSingleQuote = !inSingleQuote;
                continue;
            }

            if (ch == '"' && !inSingleQuote) {
                inDoubleQuote = !inDoubleQuote;
                continue;
            }

            if (Character.isWhitespace(ch) && !inSingleQuote && !inDoubleQuote) {
                if (current.length() > 0) {
                    tokens.add(current.toString());
                    current.setLength(0);
                }
                continue;
            }

            current.append(ch);
        }

        if (escaping) {
            current.append('\\');
        }

        if (current.length() > 0) {
            tokens.add(current.toString());
        }

        if (tokens.isEmpty()) {
            return null;
        }

        return tokens;
    }

    private static String formatDcsCode(int dcsCode) {
        return String.format("%03o", dcsCode & 0x1FF);
    }

    private static String formatCtcssTone(double toneHz) {
        return String.format("%.1f", toneHz);
    }

    private static String determineGateBlockReason(boolean dcsMatch, boolean ctcssMatch, boolean rmsGateOpen) {
        if (!dcsMatch) {
            return "dcs";
        }
        if (!ctcssMatch) {
            return "ctcss";
        }
        if (!rmsGateOpen) {
            return "silence";
        }
        return null;
    }

    private static boolean sameNullableText(String first, String second) {
        if (first == null) {
            return second == null;
        }
        return first.equals(second);
    }

    private long publishStatusIfDue(long nowNanos,
                                    long nextStatusEmitNanos,
                                    boolean dcsGateEnabled,
                                    boolean dcsGateOpen,
                                    long dcsGateSinceNanos,
                                    boolean ctcssGateEnabled,
                                    boolean ctcssGateOpen,
                                    long ctcssGateSinceNanos,
                                    boolean rmsGateOpen,
                                    long rmsGateSinceNanos,
                                    double rmsDb,
                                    double outputDb,
                                    boolean gateOpen,
                                    String gateReason,
                                    long gateSinceNanos,
                                    boolean audioDetected,
                                    long audioDetectedSinceNanos,
                                    String currentDcsLabel,
                                    String currentDcsPolarity,
                                    int dcsStatusConfidence,
                                    String currentCtcssLabel,
                                    URL streamUrl,
                                    boolean stdinMode,
                                    AudioFormat format,
                                    Path baseDir) {
        if (nowNanos < nextStatusEmitNanos) {
            return nextStatusEmitNanos;
        }

        String gateReasonValue = gateOpen ? "none" : (gateReason == null ? "unknown" : gateReason);
        String streamName = (this.streamTitle != null) ? this.streamTitle : "unknown";
        String sourceValue = buildSourceString(stdinMode, format, streamUrl);
        long pidValue = ProcessHandle.current().pid();
        ApiWebSocketServer wsServer = this.apiWebSocketServer;
        int apiPortValue = (wsServer != null) ? wsServer.getAddress().getPort() : 0;
        boolean recordingEnabledValue = (baseDir != null);
        String recordingsPathValue = (baseDir != null) ? baseDir.toAbsolutePath().toString() : null;
        double gateSecondsValue = Double.parseDouble(formatDurationSeconds(gateSinceNanos, nowNanos));
        double audioDetectedSecondsValue = Double.parseDouble(formatDurationSeconds(audioDetectedSinceNanos, nowNanos));
        List<String> audioReasonValue = new ArrayList<>(3);
        if (gateOpen) {
            if (dcsGateEnabled && dcsGateOpen) {
                audioReasonValue.add("dcs");
            }
            if (ctcssGateEnabled && ctcssGateOpen) {
                audioReasonValue.add("ctcss");
            }
            if (rmsGateOpen) {
                audioReasonValue.add("rms");
            }
        }
        publishEvent("status",
                "streamName", streamName,
                "source", sourceValue,
                "recordingEnabled", recordingEnabledValue,
                "recordingsPath", recordingsPathValue,
                "pid", pidValue,
                "apiPort", apiPortValue,
                "dcsGateEnabled", dcsGateEnabled,
                "dcs", currentDcsLabel,
                "dcsPolarity", currentDcsPolarity,
                "dcsGate", dcsGateOpen ? "open" : "closed",
                "dcsConfidence", dcsStatusConfidence,
                "ctcssGateEnabled", ctcssGateEnabled,
                "ctcss", currentCtcssLabel,
                "ctcssGate", ctcssGateOpen ? "open" : "closed",
                "rmsGate", rmsGateOpen ? "open" : "closed",
                "rmsDb", Math.round(rmsDb * 10.0) / 10.0,
                "outputDb", Math.round(outputDb * 10.0) / 10.0,
                "gate", gateOpen ? "open" : "closed",
                "gateReason", gateReasonValue,
                "gateSeconds", gateSecondsValue,
                "audioDetected", audioDetected,
                "audioDetectedSeconds", audioDetectedSecondsValue,
                "audioReason", audioReasonValue);
        return nowNanos + TimeUnit.MILLISECONDS.toNanos(250L);
    }

    private static String formatDurationSeconds(long sinceNanos, long nowNanos) {
        double seconds = Math.max(0L, nowNanos - sinceNanos) / 1_000_000_000.0;
        return String.format(Locale.US, "%.3f", seconds);
    }

    private void publishEvent(String event) {
        ApiWebSocketServer currentApiWebSocketServer = this.apiWebSocketServer;
        if (currentApiWebSocketServer == null) {
            return;
        }
        currentApiWebSocketServer.publishEvent(event,
                withEventMetadata(currentApiWebSocketServer, new Object[0]));
    }

    private void publishEvent(String event, String... keyValuePairs) {
        ApiWebSocketServer currentApiWebSocketServer = this.apiWebSocketServer;
        if (currentApiWebSocketServer == null) {
            return;
        }
        Object[] objectPairs = new Object[(keyValuePairs == null) ? 0 : keyValuePairs.length];
        if (keyValuePairs != null) {
            for (int i = 0; i < keyValuePairs.length; i++) {
                objectPairs[i] = keyValuePairs[i];
            }
        }
        currentApiWebSocketServer.publishEvent(event,
                withEventMetadata(currentApiWebSocketServer, objectPairs));
    }

    private void publishEvent(String event, Object... keyValuePairs) {
        ApiWebSocketServer currentApiWebSocketServer = this.apiWebSocketServer;
        if (currentApiWebSocketServer == null) {
            return;
        }
        currentApiWebSocketServer.publishEvent(event,
                withEventMetadata(currentApiWebSocketServer, keyValuePairs));
    }

    private static Object[] withEventMetadata(ApiWebSocketServer apiServer, Object[] keyValuePairs) {
        Object[] basePairs = (keyValuePairs == null) ? new Object[0] : keyValuePairs;
        boolean hasPid = false;
        boolean hasApiPort = false;
        boolean hasTimestamp = false;
        for (int i = 0; i + 1 < basePairs.length; i += 2) {
            Object keyObject = basePairs[i];
            if (keyObject == null) {
                continue;
            }
            String key = keyObject.toString();
            if ("pid".equals(key)) {
                hasPid = true;
            } else if ("apiPort".equals(key)) {
                hasApiPort = true;
            } else if ("timestamp".equals(key)) {
                hasTimestamp = true;
            }
        }

        int extraFields = (hasPid ? 0 : 2) + (hasApiPort ? 0 : 2) + (hasTimestamp ? 0 : 2);
        if (extraFields == 0) {
            return basePairs;
        }

        Object[] mergedPairs = Arrays.copyOf(basePairs, basePairs.length + extraFields);
        int writeIndex = basePairs.length;
        if (!hasPid) {
            mergedPairs[writeIndex++] = "pid";
            mergedPairs[writeIndex++] = ProcessHandle.current().pid();
        }
        if (!hasApiPort) {
            mergedPairs[writeIndex++] = "apiPort";
            mergedPairs[writeIndex++] = apiServer.getAddress().getPort();
        }
        if (!hasTimestamp) {
            mergedPairs[writeIndex++] = "timestamp";
            mergedPairs[writeIndex++] = System.currentTimeMillis();
        }
        return mergedPairs;
    }

    private void updateStreamLabel(HttpURLConnection conn) {
        if (this.streamNameOverride != null) {
            this.streamTitle = normalizeStreamTitle(this.streamNameOverride);
            this.streamLabel = sanitizeStreamName(this.streamTitle);
            return;
        }
        String headerName = firstNonBlank(
                conn.getHeaderField("icy-name"),
                conn.getHeaderField("x-audiocast-name"),
                conn.getHeaderField("ice-name"),
                streamUrl.getPath());
        this.streamTitle = normalizeStreamTitle(headerName);
        this.streamLabel = sanitizeStreamName(this.streamTitle);
    }

    private static boolean isBlank(String value) {
        return value == null || value.trim().isEmpty();
    }

    private static String firstNonBlank(String... values) {
        for (String value : values) {
            if (value != null && !value.trim().isEmpty()) {
                return value.trim();
            }
        }
        return "stream";
    }

    private static String sanitizeStreamName(String value) {
        String candidate = normalizeStreamTitle(value);
        int slashIndex = candidate.lastIndexOf('/');
        if (slashIndex >= 0 && slashIndex < candidate.length() - 1) {
            candidate = candidate.substring(slashIndex + 1);
        }
        candidate = candidate.replaceAll("[^A-Za-z0-9._-]+", "_");
        candidate = candidate.replaceAll("_+", "_");
        candidate = candidate.replaceAll("^_+|_+$", "");
        if (candidate.isEmpty()) {
            return "stream";
        }
        return candidate;
    }

    private static String normalizeStreamTitle(String value) {
        String candidate = firstNonBlank(value);
        int slashIndex = candidate.lastIndexOf('/');
        if (slashIndex >= 0 && slashIndex < candidate.length() - 1) {
            candidate = candidate.substring(slashIndex + 1);
        }
        candidate = candidate.replaceAll("\\.[A-Za-z0-9]{1,5}$", "").trim();
        if (candidate.isEmpty()) {
            return "stream";
        }
        return candidate;
    }

    private static String valueOrUnknown(String value) {
        return (value == null || value.trim().isEmpty()) ? "unknown" : value.trim();
    }

    private void log(String tag, String color, String message) {
        String prefix = "[" + LocalTime.now().atDate(LocalDate.now()).format(LOG_TIME_FMT) + "] [" + tag + "] ";
        PrintStream output = this.stdoutEnabled ? System.err : System.out;
        if (supportsAnsi()) {
            output.println(color + prefix + ANSI_RESET + message);
        } else {
            output.println(prefix + message);
        }
    }

    private void logError(String message) {
        String prefix = "[" + LocalTime.now().atDate(LocalDate.now()).format(LOG_TIME_FMT) + "] [ERROR] ";
        if (supportsAnsi()) {
            System.err.println(ANSI_RED + prefix + ANSI_RESET + message);
        } else {
            System.err.println(prefix + message);
        }
    }

    private static boolean supportsAnsi() {
        return System.console() != null;
    }

    private static String formatAudioEncoding(AudioFormat format) {
        if (format == null) {
            return "unknown";
        }
        String signedness = format.getEncoding() == AudioFormat.Encoding.PCM_UNSIGNED ? "u" : "s";
        int bitDepth = format.getSampleSizeInBits();
        String endianness = format.isBigEndian() ? "be" : "le";
        return signedness + bitDepth + endianness;
    }

    private static String buildSourceString(boolean stdinMode, AudioFormat format, URL streamUrl) {
        if (stdinMode) {
            if (format == null) {
                return "stdin";
            }
            int sampleRate = (int) format.getSampleRate();
            int channels = format.getChannels();
            String encoding = formatAudioEncoding(format);
            return "stdin:" + sampleRate + "," + encoding + "," + channels;
        }
        return (streamUrl != null) ? streamUrl.toString() : "unknown";
    }

    private static final class PipeOutputSession {
        private final String command;
        private Process process;
        private OutputStream stdin;
        private boolean broken;
        private long nextRestartAttemptAtMillis;

        private PipeOutputSession(String command) {
            this.command = command;
            this.process = null;
            this.stdin = null;
            this.broken = false;
            this.nextRestartAttemptAtMillis = 0L;
        }
    }

    private static final class DeviceOutputSession {
        private final SourceDataLine line;
        private final AudioFormat format;
        @SuppressWarnings("unused")
        private final Mixer.Info mixerInfo;

        private DeviceOutputSession(SourceDataLine line, AudioFormat format, Mixer.Info mixerInfo) {
            this.line = line;
            this.format = format;
            this.mixerInfo = mixerInfo;
        }
    }
}
