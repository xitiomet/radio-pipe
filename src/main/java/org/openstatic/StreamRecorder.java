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
    private final URL streamUrl;
    private final InputStream stdinInput;
    private final boolean stdinMode;
    private final boolean stdinRawMode;
    private final AudioFormat stdinRawFormat;
    private final Path baseDir;
    private final double silenceThresholdDb;
    private final double silenceDurationSeconds;
    private final float outputSampleRate;
    private final int outputChannels;
    private final int outputBitDepth;
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
                          String onWriteProgram) {
        this(streamUrl, baseDir, silenceThresholdDb, silenceDurationSeconds, outputSampleRate,
                outputChannels, outputBitDepth, onWriteProgram, null);
    }

    public StreamRecorder(URL streamUrl,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(streamUrl, null, false, false, null, streamUrl.getPath(), baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
                onWriteProgram, streamNameOverride);
    }

    public StreamRecorder(InputStream stdinInput,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                          String onWriteProgram) {
        this(stdinInput, baseDir, silenceThresholdDb, silenceDurationSeconds, outputSampleRate,
                outputChannels, outputBitDepth, onWriteProgram, null);
    }

    public StreamRecorder(InputStream stdinInput,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(null, stdinInput, true, false, null, "stdin", baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
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
                          String onWriteProgram) {
        this(stdinInput, stdinRawFormat, baseDir, silenceThresholdDb, silenceDurationSeconds,
                outputSampleRate, outputChannels, outputBitDepth, onWriteProgram, null);
    }

    public StreamRecorder(InputStream stdinInput,
                          AudioFormat stdinRawFormat,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                          String onWriteProgram,
                          String streamNameOverride) {
        this(null, stdinInput, true, true, stdinRawFormat, "stdin", baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
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
        this.outputSampleRate = outputSampleRate;
        this.outputChannels = outputChannels;
        this.outputBitDepth = outputBitDepth;
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
        this.apiWebSocketServer = null;
    }

    public void stop() {
        running = false;
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

    private boolean canWriteRecordings() {
        return this.baseDir != null;
    }

    public void run() throws Exception {
        logHookConfiguration();
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
                false);

        try (AudioInputStream din = AudioSystem.getAudioInputStream(decodedFormat, audio)) {
            if (AudioSystem.isConversionSupported(outputFormat, decodedFormat)) {
                try (AudioInputStream converted = AudioSystem.getAudioInputStream(outputFormat, din)) {
                    log("READY", ANSI_GREEN,
                            String.format(
                                    "Monitoring audio at %.0f Hz, %d channel(s), %d-bit; silence threshold %.1f dB for %.1f s",
                                    outputFormat.getSampleRate(),
                                    outputFormat.getChannels(),
                                    outputFormat.getSampleSizeInBits(),
                                    silenceThresholdDb,
                                    silenceDurationSeconds));
                    logDcsConfiguration();
                    logCtcssConfiguration();
                    logStdoutConfiguration(outputFormat);
                    processStream(converted, outputFormat);
                }
            } else {
                log("FORMAT", ANSI_YELLOW,
                        "Requested output format is not supported by this JVM; using decoded stream format.");
                log("READY", ANSI_GREEN,
                        String.format(
                                "Monitoring audio at %.0f Hz, %d channel(s), %d-bit; silence threshold %.1f dB for %.1f s",
                                decodedFormat.getSampleRate(),
                                decodedFormat.getChannels(),
                                decodedFormat.getSampleSizeInBits(),
                                silenceThresholdDb,
                                silenceDurationSeconds));
                logDcsConfiguration();
                logCtcssConfiguration();
                logStdoutConfiguration(decodedFormat);
                processStream(din, decodedFormat);
            }
        }
    }

    private void processStream(AudioInputStream din, AudioFormat format) throws IOException {
        int frameSize = format.getFrameSize();
        float frameRate = format.getFrameRate() > 0 ? format.getFrameRate() : format.getSampleRate();
        long framesForSilence = (long) (silenceDurationSeconds * frameRate);
        boolean dcsGateEnabled = (this.requiredDcsCode != null);
        boolean ctcssGateEnabled = (this.requiredCtcssToneHz != null);
        DcsDetector dcsStatusDetector = new DcsDetector(format);
        CtcssDetector ctcssStatusDetector = new CtcssDetector(format);
        DcsDetector dcsGateDetector = dcsGateEnabled ? new DcsDetector(format, this.requiredDcsCode) : null;
        CtcssDetector ctcssGateDetector = ctcssGateEnabled ? new CtcssDetector(format, this.requiredCtcssToneHz) : null;
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
        boolean statusGateOpen = false;
        String statusGateReason = "silence";
        long statusGateSinceNanos = statusClockNanos;
        boolean statusAudioDetected = false;
        long statusAudioDetectedSinceNanos = statusClockNanos;
        String statusDetectedDcsLabel = null;
        String statusDetectedDcsPolarity = null;
        String statusDetectedCtcssLabel = null;
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
                if (this.stdoutEnabled && this.stdoutRawMode && this.stdoutPadMode) {
                    int padBytes = padFramesPerTick * frameSize;
                    byte[] padSilence = new byte[padBytes];
                    stdoutPadBuffer.addLast(padSilence);
                    drainStdoutPadBuffer(stdoutPadBuffer, padBytes, format);

                    if (activelyRecording) {
                        chunk.write(padSilence, 0, padBytes);
                        recordedFrames += padFramesPerTick;
                    }

                    silentFrames += padFramesPerTick;
                    if (silentFrames >= framesForSilence && chunk.size() > 0) {
                        log("SILENCE", ANSI_YELLOW,
                                String.format("Silence reached after %.1f s, closing clip.",
                                        recordedFrames / frameRate));
                        publishEvent("silence_detected");
                        byte[] clipAudio = chunk.toByteArray();
                        double soundSeconds = soundFrames / frameRate;
                        if (soundSeconds > 1.0) {
                            if (canWriteRecordings()) {
                                writeChunk(clipAudio, format, chunkStartTime);
                            }
                            if (this.stdoutEnabled && !this.stdoutRawMode) {
                                writeChunkToStdoutWav(clipAudio, format);
                            }
                        } else {
                            log("RECORD", ANSI_YELLOW,
                                    String.format("Discarding clip with only %.1f s of sound.",
                                            soundSeconds));
                        }
                        chunk.reset();
                        activelyRecording = false;
                        recordedFrames = 0;
                        soundFrames = 0;
                        silentFrames = 0;
                    }
                }
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
                        statusGateOpen,
                        statusGateReason,
                        statusGateSinceNanos,
                        statusAudioDetected,
                        statusAudioDetectedSinceNanos,
                        statusDetectedDcsLabel,
                        statusDetectedDcsPolarity,
                        statusDetectedCtcssLabel,
                        this.streamUrl,
                        this.stdinMode,
                        format,
                        this.baseDir);
                continue;
            }

            boolean isSilent = isSilent(currentBuffer, n, format);
            double currentRmsDb = calculateRmsDb(currentBuffer, n, format);
            statusRmsDb = currentRmsDb;  // track current RMS level
            long nowNanos = System.nanoTime();

            dcsStatusDetector.consume(currentBuffer, n, format);
            Integer detectedDcsCode = dcsStatusDetector.getDetectedCode();
            statusDetectedDcsLabel = (detectedDcsCode == null) ? null : formatDcsCode(detectedDcsCode.intValue());
            statusDetectedDcsPolarity = (detectedDcsCode == null) ? null : dcsStatusDetector.getPolarityLabel();

            boolean dcsRawMatch = !dcsGateEnabled || dcsGateDetector.consume(currentBuffer, n, format);
            if (dcsGateEnabled && dcsRawMatch && gateHoldNanos > 0L) {
                long holdUntil = nowNanos + gateHoldNanos;
                dcsGateHoldUntilNanos = (holdUntil < 0L) ? Long.MAX_VALUE : holdUntil;
            }
            boolean dcsMatch = dcsRawMatch;
            if (!dcsMatch && dcsGateEnabled && gateHoldNanos > 0L && nowNanos <= dcsGateHoldUntilNanos) {
                dcsMatch = true;
            }

            ctcssStatusDetector.consume(currentBuffer, n, format);
            Double detectedCtcssTone = ctcssStatusDetector.getDetectedToneHz();
            statusDetectedCtcssLabel = (detectedCtcssTone == null) ? null : formatCtcssTone(detectedCtcssTone.doubleValue());

            boolean ctcssRawMatch = !ctcssGateEnabled || ctcssGateDetector.consume(currentBuffer, n, format);
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
                    publishEvent("dcs_detected",
                            "dcs", this.requiredDcsLabel,
                            "polarity", dcsGateDetector.getPolarityLabel());
                } else {
                    log("DCS", ANSI_YELLOW,
                            "DCS " + this.requiredDcsLabel + " no longer detected, gate closed.");
                    publishEvent("dcs_gone",
                            "dcs", this.requiredDcsLabel,
                            "polarity", dcsGateDetector.getPolarityLabel());
                }
            }
            if (ctcssGateEnabled && ctcssMatch != ctcssGateOpen) {
                ctcssGateOpen = ctcssMatch;
                if (ctcssGateOpen) {
                    log("CTCSS", ANSI_GREEN,
                            "CTCSS " + this.requiredCtcssLabel + " detected, gate open.");
                    publishEvent("ctcss_detected",
                            "ctcss", this.requiredCtcssLabel);
                } else {
                    log("CTCSS", ANSI_YELLOW,
                            "CTCSS " + this.requiredCtcssLabel + " no longer detected, gate closed.");
                    publishEvent("ctcss_gone",
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
            boolean rmsGateOpen = !isSilent;
            if (statusRmsGateOpen != rmsGateOpen) {
                statusRmsGateOpen = rmsGateOpen;
                statusRmsGateSinceNanos = nowNanos;
            }

            String currentGateBlockReason = determineGateBlockReason(isSilent, dcsMatch, ctcssMatch);
            boolean currentGateOpen = (currentGateBlockReason == null);
            if (statusGateOpen != currentGateOpen || !sameNullableText(statusGateReason, currentGateBlockReason)) {
                if (currentGateOpen) {
                    publishEvent("gate_open", "reason", (statusGateReason == null) ? "none" : statusGateReason);
                } else {
                    publishEvent("gate_closed", "reason", currentGateBlockReason);
                }
                statusGateOpen = currentGateOpen;
                statusGateReason = currentGateBlockReason;
                statusGateSinceNanos = nowNanos;
            }

            boolean gateAllowsAudio = !isSilent && dcsMatch && ctcssMatch;
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
                    statusGateOpen,
                    statusGateReason,
                    statusGateSinceNanos,
                    statusAudioDetected,
                    statusAudioDetectedSinceNanos,
                    statusDetectedDcsLabel,
                    statusDetectedDcsPolarity,
                    statusDetectedCtcssLabel,
                    this.streamUrl,
                    this.stdinMode,
                    format,
                    this.baseDir);
            boolean includeFrameInClip = gateAllowsAudio || activelyRecording;
            if (this.stdoutEnabled && this.stdoutRawMode) {
                if (this.stdoutPadMode) {
                    byte[] stdoutFrame = includeFrameInClip
                            ? Arrays.copyOf(currentBuffer, n)
                            : new byte[n];
                    stdoutPadBuffer.addLast(stdoutFrame);
                    drainStdoutPadBuffer(stdoutPadBuffer, n, format);
                } else if (includeFrameInClip) {
                    writeStdoutRaw(currentBuffer, n, format);
                }
            }

            if (gateAllowsAudio) {
                if (chunk.size() == 0) {
                    chunkStartTime = System.currentTimeMillis();
                    log("RECORD", ANSI_GREEN,
                            "Audio detected, starting clip for stream " + streamLabel + ".");
                    publishEvent("audio_detected");
                }
                chunk.write(currentBuffer, 0, n);
                recordedFrames += n / frameSize;
                soundFrames += n / frameSize;
                silentFrames = 0;
                activelyRecording = true;
            } else {
                if (activelyRecording) {
                    chunk.write(currentBuffer, 0, n);
                    recordedFrames += n / frameSize;
                }
                silentFrames += n / frameSize;
                if (silentFrames >= framesForSilence && chunk.size() > 0) {
                    log("SILENCE", ANSI_YELLOW,
                            String.format("Silence reached after %.1f s, closing clip.",
                                    recordedFrames / frameRate));
                    publishEvent("silence_detected");
                    byte[] clipAudio = chunk.toByteArray();
                    double soundSeconds = soundFrames / frameRate;
                    if (soundSeconds > 1.0) { // only keep clips that have at least 1 second of sound
                        if (canWriteRecordings()) {
                            writeChunk(clipAudio, format, chunkStartTime);
                        }
                        if (this.stdoutEnabled && !this.stdoutRawMode) {
                            writeChunkToStdoutWav(clipAudio, format);
                        }
                    } else {
                        log("RECORD", ANSI_YELLOW,
                                String.format("Discarding clip with only %.1f s of sound.",
                                        soundSeconds));
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
                    writeChunk(clipAudio, format, chunkStartTime);
                }
                if (this.stdoutEnabled && !this.stdoutRawMode) {
                    writeChunkToStdoutWav(clipAudio, format);
                }
            } else {
                log("RECORD", ANSI_YELLOW,
                        String.format("Discarding clip with only %.1f s of sound.",
                                soundSeconds));
            }
            chunk.reset();
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

    private void writeChunkToStdoutWav(byte[] audioData, AudioFormat format) {
        try (ByteArrayInputStream bais = new ByteArrayInputStream(audioData);
             AudioInputStream ais = new AudioInputStream(bais, format, audioData.length / format.getFrameSize())) {
            synchronized (System.out) {
                AudioSystem.write(ais, AudioFileFormat.Type.WAVE, System.out);
                System.out.flush();
            }
        } catch (IOException ioe) {
            logError("Failed to write WAV clip to stdout: " + ioe.getMessage());
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

    private boolean isSilent(byte[] data, int len, AudioFormat format) {
        return calculateRmsDb(data, len, format) < silenceThresholdDb;
    }

    private double calculateRmsDb(byte[] data, int len, AudioFormat format) {
        int sampleSize = format.getSampleSizeInBits();
        boolean bigEndian = format.isBigEndian();
        int channels = format.getChannels();
        if (sampleSize != 16) {
            // we only converted to 16‑bit; other cases should not happen
            return -100.0;  // return very low dB value for unsupported format
        }
        int frames = len / format.getFrameSize();
        double sumSq = 0.0;
        int offset = 0;
        for (int i = 0; i < frames; i++) {
            for (int ch = 0; ch < channels; ch++) {
                int lo = data[offset] & 0xff;
                int hi = data[offset + 1];
                int sample;
                if (bigEndian) {
                    sample = (hi << 8) | lo;
                } else {
                    sample = (lo) | (hi << 8);
                }
                double norm = sample / 32768.0;
                sumSq += norm * norm;
                offset += 2;
            }
        }
        double rms = Math.sqrt(sumSq / (frames * channels));
        double db = 20 * Math.log10(rms + 1e-10); // avoid log(0)
        return db;
    }

    private void writeChunk(byte[] audioData, AudioFormat format, long startTimeMs) {
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
            try (ByteArrayInputStream bais = new ByteArrayInputStream(audioData);
                 AudioInputStream ais = new AudioInputStream(bais, format, audioData.length / format.getFrameSize())) {
                AudioSystem.write(ais, AudioFileFormat.Type.WAVE, out.toFile());
            }
            try {
                appendWavInfoMetadata(out);
            } catch (IOException metadataException) {
                log("WRITE", ANSI_YELLOW,
                        "Saved clip but could not append WAV metadata: " + metadataException.getMessage());
            }
            log("WRITE", ANSI_GREEN, "Saved clip: " + out);
            runOnWriteProgram(out.toAbsolutePath());
        } catch (IOException ioe) {
            logError("Failed to write chunk: " + ioe.getMessage());
            ioe.printStackTrace(System.err);
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
                publishEvent("hook_executed", "command", String.join(" ", command));
                ProcessBuilder pb = new ProcessBuilder(command);
                pb.redirectErrorStream(true);
                Process process = pb.start();

                try (BufferedReader outputReader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
                    String line;
                    while ((line = outputReader.readLine()) != null) {
                        log("HOOK", ANSI_BLUE, line);
                        publishEvent("hook_result", "result", line);
                    }
                }

                int exitCode = process.waitFor();
                if (exitCode == 0) {
                    log("HOOK", ANSI_GREEN, "Completed for " + wavFile);
                } else {
                    log("HOOK", ANSI_YELLOW,
                            "Exited with code " + exitCode + " for " + wavFile);
                }
                publishEvent("hook_result", "result", "exit_code=" + exitCode);
            } catch (Exception e) {
                logError("on-write execution failed for " + wavFile + ": " + e.getMessage());
                publishEvent("hook_result", "result", "error: " + e.getMessage());
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

    private static String determineGateBlockReason(boolean isSilent, boolean dcsMatch, boolean ctcssMatch) {
        if (isSilent) {
            return "silence";
        }
        if (!dcsMatch) {
            return "dcs";
        }
        if (!ctcssMatch) {
            return "ctcss";
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
                                    boolean gateOpen,
                                    String gateReason,
                                    long gateSinceNanos,
                                    boolean audioDetected,
                                    long audioDetectedSinceNanos,
                                    String currentDcsLabel,
                                    String currentDcsPolarity,
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
        String recordingsPathValue = (baseDir != null) ? baseDir.toAbsolutePath().toString() : null;
        double gateSecondsValue = Double.parseDouble(formatDurationSeconds(gateSinceNanos, nowNanos));
        double audioDetectedSecondsValue = Double.parseDouble(formatDurationSeconds(audioDetectedSinceNanos, nowNanos));
        publishEvent("status",
                "stream_name", streamName,
                "source", sourceValue,
                "recordingsPath", recordingsPathValue,
                "pid", pidValue,
                "api_port", apiPortValue,
                "dcs_gate_enabled", String.valueOf(dcsGateEnabled),
                "dcs", currentDcsLabel,
                "dcs_polarity", currentDcsPolarity,
                "dcs_gate", dcsGateOpen ? "open" : "closed",
                "ctcss_gate_enabled", String.valueOf(ctcssGateEnabled),
                "ctcss", currentCtcssLabel,
                "ctcss_gate", ctcssGateOpen ? "open" : "closed",
                "rms_gate", rmsGateOpen ? "open" : "closed",
                "rms_db", Math.round(rmsDb * 10.0) / 10.0,
                "gate", gateOpen ? "open" : "closed",
                "gate_reason", gateReasonValue,
                "gate_seconds", gateSecondsValue,
                "audio_detected", String.valueOf(audioDetected),
                "audio_detected_seconds", audioDetectedSecondsValue);
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
        currentApiWebSocketServer.publishEvent(event);
    }

    private void publishEvent(String event, String... keyValuePairs) {
        ApiWebSocketServer currentApiWebSocketServer = this.apiWebSocketServer;
        if (currentApiWebSocketServer == null) {
            return;
        }
        currentApiWebSocketServer.publishEvent(event, keyValuePairs);
    }

    private void publishEvent(String event, Object... keyValuePairs) {
        ApiWebSocketServer currentApiWebSocketServer = this.apiWebSocketServer;
        if (currentApiWebSocketServer == null) {
            return;
        }
        currentApiWebSocketServer.publishEvent(event, keyValuePairs);
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
}
