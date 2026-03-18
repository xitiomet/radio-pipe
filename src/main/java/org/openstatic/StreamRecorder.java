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
import java.util.ArrayList;
import java.util.Arrays;
import java.util.List;
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
    private static final double DCS_BITRATE = 134.4;
    private static final int DCS_CODEWORD_BITS = 23;
    private static final int DCS_CODEWORD_MASK = 0x7FFFFF;
    private static final int DCS_GOLAY_POLY = 0xC75;
    private static final long PLL_PHASE_MASK = 0xFFFFFFFFL;
    private static final long PLL_PHASE_MIDPOINT = 0x80000000L;
    private static final long PLL_PHASE_WRAP = 0x1_0000_0000L;
    private static final long STDOUT_READ_POLL_MILLIS = 20L;
    private static final long DEFAULT_STDOUT_PAD_DELAY_MILLIS = 500L;
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
    private volatile boolean stdoutEnabled;
    private volatile boolean stdoutRawMode;
    private volatile AudioFormat stdoutRawFormat;
    private volatile boolean stdoutPadMode;
    private volatile long stdoutPadDelayMillis;
    private volatile boolean stdoutRawConversionWarned;
    private volatile boolean stdoutConfigLogged;
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
        this.stdoutEnabled = false;
        this.stdoutRawMode = false;
        this.stdoutRawFormat = null;
        this.stdoutPadMode = false;
        this.stdoutPadDelayMillis = DEFAULT_STDOUT_PAD_DELAY_MILLIS;
        this.stdoutRawConversionWarned = false;
        this.stdoutConfigLogged = false;
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
                conn.setRequestProperty("User-Agent", "rms-cast-recorder/1.0");
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
        DcsDetector dcsDetector = (this.requiredDcsCode == null) ? null : new DcsDetector(format, this.requiredDcsCode);
        CtcssDetector ctcssDetector = (this.requiredCtcssToneHz == null) ? null : new CtcssDetector(format, this.requiredCtcssToneHz);
        boolean dcsGateOpen = (dcsDetector == null);
        boolean ctcssGateOpen = (ctcssDetector == null);

        byte[] buffer = new byte[frameSize * 1024];
        ByteArrayOutputStream chunk = new ByteArrayOutputStream();
        long recordedFrames = 0;
        long soundFrames = 0;
        long silentFrames = 0;
        long chunkStartTime = 0;
        boolean activelyRecording = false;
        int padFramesPerTick = Math.max(1, (int) Math.round((STDOUT_READ_POLL_MILLIS / 1000.0) * frameRate));
        long stalledMillis = 0L;

        BlockingQueue<StreamReadResult> readQueue = new LinkedBlockingQueue<>();
        Thread readerThread = new Thread(() -> {
            try {
                int n;
                while (running && (n = din.read(buffer)) != -1) {
                    byte[] copy = Arrays.copyOf(buffer, n);
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
                readResult = readQueue.poll(STDOUT_READ_POLL_MILLIS, TimeUnit.MILLISECONDS);
            } catch (InterruptedException interruptedException) {
                Thread.currentThread().interrupt();
                break;
            }

            if (readResult == null) {
                if (this.stdoutEnabled && this.stdoutRawMode && this.stdoutPadMode) {
                    stalledMillis += STDOUT_READ_POLL_MILLIS;
                    if (stalledMillis < this.stdoutPadDelayMillis) {
                        continue;
                    }

                    int padBytes = padFramesPerTick * frameSize;
                    byte[] padBuffer = new byte[padBytes];
                    writeStdoutRaw(padBuffer, padBytes, format);

                    if (activelyRecording) {
                        chunk.write(padBuffer, 0, padBytes);
                        recordedFrames += padFramesPerTick;
                    }

                    silentFrames += padFramesPerTick;
                    if (silentFrames >= framesForSilence && chunk.size() > 0) {
                        log("SILENCE", ANSI_YELLOW,
                                String.format("Silence reached after %.1f s, closing clip.",
                                        recordedFrames / frameRate));
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
                continue;
            }

            stalledMillis = 0L;

            if (readResult.error != null) {
                throw new IOException("Audio stream read failed", readResult.error);
            }

            if (readResult.endOfStream) {
                break;
            }

            int n = readResult.length;
            byte[] currentBuffer = readResult.data;
            boolean isSilent = isSilent(currentBuffer, n, format);
            boolean dcsMatch = (dcsDetector == null) || dcsDetector.consume(currentBuffer, n, format);
            boolean ctcssMatch = (ctcssDetector == null) || ctcssDetector.consume(currentBuffer, n, format);
            if (dcsDetector != null && dcsMatch != dcsGateOpen) {
                dcsGateOpen = dcsMatch;
                if (dcsGateOpen) {
                    log("DCS", ANSI_GREEN,
                            "DCS " + this.requiredDcsLabel + " detected ("
                                    + dcsDetector.getPolarityLabel() + "), gate open.");
                } else {
                    log("DCS", ANSI_YELLOW,
                            "DCS " + this.requiredDcsLabel + " no longer detected, gate closed.");
                }
            }
            if (ctcssDetector != null && ctcssMatch != ctcssGateOpen) {
                ctcssGateOpen = ctcssMatch;
                if (ctcssGateOpen) {
                    log("CTCSS", ANSI_GREEN,
                            "CTCSS " + this.requiredCtcssLabel + " detected, gate open.");
                } else {
                    log("CTCSS", ANSI_YELLOW,
                            "CTCSS " + this.requiredCtcssLabel + " no longer detected, gate closed.");
                }
            }

            if (!isSilent && dcsMatch && ctcssMatch) {
                if (chunk.size() == 0) {
                    chunkStartTime = System.currentTimeMillis();
                    log("RECORD", ANSI_GREEN,
                            "Audio detected, starting clip for stream " + streamLabel + ".");
                }
                chunk.write(currentBuffer, 0, n);
                if (this.stdoutEnabled && this.stdoutRawMode) {
                    writeStdoutRaw(currentBuffer, n, format);
                }
                recordedFrames += n / frameSize;
                soundFrames += n / frameSize;
                silentFrames = 0;
                activelyRecording = true;
            } else {
                if (activelyRecording) {
                    chunk.write(currentBuffer, 0, n);
                    if (this.stdoutEnabled && this.stdoutRawMode) {
                        writeStdoutRaw(currentBuffer, n, format);
                    }
                    recordedFrames += n / frameSize;
                }
                silentFrames += n / frameSize;
                if (silentFrames >= framesForSilence && chunk.size() > 0) {
                    log("SILENCE", ANSI_YELLOW,
                            String.format("Silence reached after %.1f s, closing clip.",
                                    recordedFrames / frameRate));
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

        log("DCS", ANSI_CYAN,
                "DCS gate enabled for code " + this.requiredDcsLabel
                        + " (normal and inverted polarity, " + DCS_BITRATE + " bps). Clips open only on matching DCS.");
    }

    private void logCtcssConfiguration() {
        if (this.requiredCtcssToneHz == null) {
            return;
        }

        log("CTCSS", ANSI_CYAN,
                "CTCSS gate enabled for tone " + this.requiredCtcssLabel
                        + " Hz. Clips open only on matching tone.");
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
                        "stdout pad enabled: emit silence only after input stalls for "
                                + this.stdoutPadDelayMillis + " ms.");
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

    private static final class StreamReadResult {
        private final byte[] data;
        private final int length;
        private final boolean endOfStream;
        private final Exception error;

        private StreamReadResult(byte[] data, int length, boolean endOfStream, Exception error) {
            this.data = data;
            this.length = length;
            this.endOfStream = endOfStream;
            this.error = error;
        }

        private static StreamReadResult data(byte[] data, int length) {
            return new StreamReadResult(data, length, false, null);
        }

        private static StreamReadResult eof() {
            return new StreamReadResult(null, 0, true, null);
        }

        private static StreamReadResult error(Exception error) {
            return new StreamReadResult(null, 0, false, error);
        }
    }

    private boolean isSilent(byte[] data, int len, AudioFormat format) {
        int sampleSize = format.getSampleSizeInBits();
        boolean bigEndian = format.isBigEndian();
        int channels = format.getChannels();
        if (sampleSize != 16) {
            // we only converted to 16‑bit; other cases should not happen
            return false;
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
        return db < silenceThresholdDb;
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
                ProcessBuilder pb = new ProcessBuilder(command);
                pb.redirectErrorStream(true);
                Process process = pb.start();

                try (BufferedReader outputReader = new BufferedReader(new InputStreamReader(process.getInputStream()))) {
                    String line;
                    while ((line = outputReader.readLine()) != null) {
                        log("HOOK", ANSI_BLUE, line);
                    }
                }

                int exitCode = process.waitFor();
                if (exitCode == 0) {
                    log("HOOK", ANSI_GREEN, "Completed for " + wavFile);
                } else {
                    log("HOOK", ANSI_YELLOW,
                            "Exited with code " + exitCode + " for " + wavFile);
                }
            } catch (Exception e) {
                logError("on-write execution failed for " + wavFile + ": " + e.getMessage());
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

    private static final class CtcssDetector {
        private static final double MIN_TONE_HZ = 50.0;
        private static final double MAX_TONE_HZ = 300.0;
        private static final double ANALYSIS_WINDOW_SECONDS = 0.2;
        private static final int OPEN_MATCH_WINDOWS = 2;
        private static final int CLOSE_MISS_WINDOWS = 3;
        private static final double MIN_TARGET_AMPLITUDE = 0.0025;
        private static final double DOMINANCE_RATIO = 1.6;
        private static final double NEIGHBOR_OFFSET_HZ = 4.0;

        private final double sampleRate;
        private final double targetHz;
        private final double lowerNeighborHz;
        private final double upperNeighborHz;
        private final int windowSamples;
        private final double[] sampleWindow;
        private int sampleIndex;
        private int matchWindows;
        private int missWindows;
        private boolean detected;

        private CtcssDetector(AudioFormat format, double targetHz) {
            if (format.getSampleRate() <= 0) {
                throw new IllegalArgumentException("Invalid sample rate for CTCSS detector");
            }

            this.sampleRate = format.getSampleRate();
            this.targetHz = targetHz;
            this.lowerNeighborHz = chooseLowerNeighbor(targetHz);
            this.upperNeighborHz = chooseUpperNeighbor(targetHz);
            this.windowSamples = Math.max(200, (int) Math.round(this.sampleRate * ANALYSIS_WINDOW_SECONDS));
            this.sampleWindow = new double[this.windowSamples];
            this.sampleIndex = 0;
            this.matchWindows = 0;
            this.missWindows = 0;
            this.detected = false;
        }

        private static double chooseLowerNeighbor(double targetHz) {
            double candidate = Math.max(MIN_TONE_HZ, targetHz - NEIGHBOR_OFFSET_HZ);
            if (Math.abs(candidate - targetHz) < 0.001) {
                candidate = Math.min(MAX_TONE_HZ, targetHz + (NEIGHBOR_OFFSET_HZ * 2.0));
            }
            return candidate;
        }

        private static double chooseUpperNeighbor(double targetHz) {
            double candidate = Math.min(MAX_TONE_HZ, targetHz + NEIGHBOR_OFFSET_HZ);
            if (Math.abs(candidate - targetHz) < 0.001) {
                candidate = Math.max(MIN_TONE_HZ, targetHz - (NEIGHBOR_OFFSET_HZ * 2.0));
            }
            return candidate;
        }

        private boolean consume(byte[] data, int len, AudioFormat format) {
            if (format.getSampleSizeInBits() != 16) {
                return false;
            }

            int frameSize = format.getFrameSize();
            int channels = format.getChannels();
            boolean bigEndian = format.isBigEndian();
            int frames = len / frameSize;
            int offset = 0;

            for (int i = 0; i < frames; i++) {
                double mixedSample = 0.0;
                for (int ch = 0; ch < channels; ch++) {
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
                    mixedSample += sample / 32768.0;
                    offset += 2;
                }

                this.sampleWindow[this.sampleIndex++] = mixedSample / channels;
                if (this.sampleIndex >= this.windowSamples) {
                    analyzeWindow();
                    this.sampleIndex = 0;
                }
            }

            return this.detected;
        }

        private void analyzeWindow() {
            double mean = 0.0;
            for (int i = 0; i < this.windowSamples; i++) {
                mean += this.sampleWindow[i];
            }
            mean /= this.windowSamples;

            double targetAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.targetHz, this.sampleRate, mean);
            double lowerAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.lowerNeighborHz, this.sampleRate, mean);
            double upperAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.upperNeighborHz, this.sampleRate, mean);

            boolean match = targetAmp >= MIN_TARGET_AMPLITUDE
                    && targetAmp >= (lowerAmp * DOMINANCE_RATIO)
                    && targetAmp >= (upperAmp * DOMINANCE_RATIO);

            if (match) {
                this.matchWindows++;
                this.missWindows = 0;
            } else {
                this.missWindows++;
                this.matchWindows = 0;
            }

            if (!this.detected && this.matchWindows >= OPEN_MATCH_WINDOWS) {
                this.detected = true;
            } else if (this.detected && this.missWindows >= CLOSE_MISS_WINDOWS) {
                this.detected = false;
            }
        }

        private static double goertzelAmplitude(double[] samples,
                                                int sampleCount,
                                                double targetHz,
                                                double sampleRate,
                                                double mean) {
            double omega = (2.0 * Math.PI * targetHz) / sampleRate;
            double coeff = 2.0 * Math.cos(omega);
            double prev = 0.0;
            double prev2 = 0.0;

            for (int i = 0; i < sampleCount; i++) {
                double centered = samples[i] - mean;
                double next = centered + (coeff * prev) - prev2;
                prev2 = prev;
                prev = next;
            }

            double power = (prev2 * prev2) + (prev * prev) - (coeff * prev * prev2);
            if (power < 0.0) {
                power = 0.0;
            }

            return (2.0 * Math.sqrt(power)) / sampleCount;
        }
    }

    private static boolean isValidGolayCodeword(int codeword) {
        int remainder = codeword & DCS_CODEWORD_MASK;
        for (int shift = 11; shift >= 0; shift--) {
            if ((remainder & (1 << (shift + 11))) != 0) {
                remainder ^= (DCS_GOLAY_POLY << shift);
            }
        }
        return (remainder & DCS_CODEWORD_MASK) == 0;
    }

    private static final class DcsDetector {
        private static final double FILTER_CUTOFF_HZ = 300.0;
        private static final double COMPARATOR_THRESHOLD = 500.0 / 32768.0;
        private final int targetCode;
        private final long pllIncrement;
        private final long holdSamples;
        private final double b0;
        private final double b1;
        private final double b2;
        private final double a1;
        private final double a2;
        private double x1;
        private double x2;
        private double y1;
        private double y2;
        private int lastComparatorBit;
        private int previousInputBit;
        private long pllPhase;
        private int shiftRegister;
        private long totalBits;
        private int matchCount;
        private int bitsSinceMatch;
        private int matchedPolarity;
        private int lastPolarity;
        private long sampleCursor;
        private long lastConfirmedSample;

        private DcsDetector(AudioFormat format, int targetCode) {
            if (format.getSampleRate() <= 0) {
                throw new IllegalArgumentException("Invalid sample rate for DCS detector");
            }

            this.targetCode = targetCode & 0x1FF;
            this.bitsSinceMatch = -1;
            this.matchedPolarity = -1;
            this.lastPolarity = -1;
            this.lastConfirmedSample = Long.MIN_VALUE;

            double sampleRate = format.getSampleRate();
            double wc = Math.tan(Math.PI * FILTER_CUTOFF_HZ / sampleRate);
            double wcSquared = wc * wc;
            double sqrt2 = Math.sqrt(2.0);
            double norm = 1.0 / (1.0 + sqrt2 * wc + wcSquared);
            this.b0 = wcSquared * norm;
            this.b1 = 2.0 * this.b0;
            this.b2 = this.b0;
            this.a1 = 2.0 * (wcSquared - 1.0) * norm;
            this.a2 = (1.0 - sqrt2 * wc + wcSquared) * norm;

            this.pllIncrement = Math.max(1L,
                    Math.round((DCS_BITRATE / sampleRate) * PLL_PHASE_WRAP));
            this.holdSamples = Math.max(1L, Math.round(sampleRate));
        }

        private boolean consume(byte[] data, int len, AudioFormat format) {
            if (format.getSampleSizeInBits() != 16) {
                return false;
            }

            int frameSize = format.getFrameSize();
            int channels = format.getChannels();
            boolean bigEndian = format.isBigEndian();
            int frames = len / frameSize;
            int offset = 0;

            for (int i = 0; i < frames; i++) {
                double mixedSample = 0.0;
                for (int ch = 0; ch < channels; ch++) {
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
                    mixedSample += sample / 32768.0;
                    offset += 2;
                }
                mixedSample /= channels;
                processSample(mixedSample);
            }

            return isGateOpen();
        }

        private void processSample(double sample) {
            double filtered = (this.b0 * sample)
                    + (this.b1 * this.x1)
                    + (this.b2 * this.x2)
                    - (this.a1 * this.y1)
                    - (this.a2 * this.y2);

            this.x2 = this.x1;
            this.x1 = sample;
            this.y2 = this.y1;
            this.y1 = filtered;

            int bit = comparator(filtered);

            if (bit != this.previousInputBit) {
                long phaseError = (this.pllPhase < PLL_PHASE_MIDPOINT)
                        ? this.pllPhase
                        : (this.pllPhase - PLL_PHASE_WRAP);
                this.pllPhase = (this.pllPhase - (phaseError >> 4)) & PLL_PHASE_MASK;
            }
            this.previousInputBit = bit;

            long previousPhase = this.pllPhase;
            this.pllPhase = (this.pllPhase + this.pllIncrement) & PLL_PHASE_MASK;

            if (previousPhase < PLL_PHASE_MIDPOINT && this.pllPhase >= PLL_PHASE_MIDPOINT) {
                sampleBit(bit);
            }

            this.sampleCursor++;
        }

        private int comparator(double sample) {
            if (sample > COMPARATOR_THRESHOLD) {
                this.lastComparatorBit = 1;
            } else if (sample < -COMPARATOR_THRESHOLD) {
                this.lastComparatorBit = 0;
            }
            return this.lastComparatorBit;
        }

        private void sampleBit(int bit) {
            this.shiftRegister = ((this.shiftRegister >>> 1) | (bit << 22)) & DCS_CODEWORD_MASK;
            this.totalBits++;
            checkForMatch();
        }

        private void checkForMatch() {
            if (this.totalBits < DCS_CODEWORD_BITS) {
                return;
            }

            if (this.bitsSinceMatch >= 0) {
                this.bitsSinceMatch++;
                if (this.bitsSinceMatch < DCS_CODEWORD_BITS) {
                    return;
                }
            }

            int foundPolarity = -1;
            int normalCode = extractCode(this.shiftRegister, false);
            if (normalCode == this.targetCode) {
                foundPolarity = 0;
            } else {
                int invertedCode = extractCode(this.shiftRegister, true);
                if (invertedCode == this.targetCode) {
                    foundPolarity = 1;
                }
            }

            if (foundPolarity >= 0) {
                if (this.bitsSinceMatch == DCS_CODEWORD_BITS && foundPolarity == this.matchedPolarity) {
                    this.matchCount++;
                } else {
                    this.matchCount = 1;
                    this.matchedPolarity = foundPolarity;
                }

                this.bitsSinceMatch = 0;
                if (this.matchCount >= 3) {
                    this.lastConfirmedSample = this.sampleCursor;
                    this.lastPolarity = foundPolarity;
                }
            } else if (this.bitsSinceMatch >= DCS_CODEWORD_BITS) {
                this.matchCount = 0;
                this.bitsSinceMatch = -1;
                this.matchedPolarity = -1;
            }
        }

        private int extractCode(int codeword, boolean invert) {
            int candidate = invert ? (codeword ^ DCS_CODEWORD_MASK) : codeword;
            if (!isValidGolayCodeword(candidate)) {
                return -1;
            }

            int data12 = (candidate >>> 11) & 0xFFF;
            if ((data12 & 0xE00) != 0x800) {
                return -1;
            }

            return data12 & 0x1FF;
        }

        private boolean isGateOpen() {
            if (this.lastConfirmedSample < 0) {
                return false;
            }
            return (this.sampleCursor - this.lastConfirmedSample) <= this.holdSamples;
        }

        private String getPolarityLabel() {
            if (this.lastPolarity == 0) {
                return "normal";
            }
            if (this.lastPolarity == 1) {
                return "inverted";
            }
            return "unknown";
        }
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
}
