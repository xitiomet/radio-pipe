package org.openstatic;

import javax.sound.sampled.*;
import java.io.*;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
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
    private volatile boolean running = true;
    private volatile String streamLabel;
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
        this(streamUrl, null, false, false, null, streamUrl.getPath(), baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
                onWriteProgram);
    }

    public StreamRecorder(InputStream stdinInput,
                          Path baseDir,
                          double silenceThresholdDb,
                          double silenceDurationSeconds,
                          float outputSampleRate,
                          int outputChannels,
                          int outputBitDepth,
                          String onWriteProgram) {
        this(null, stdinInput, true, false, null, "stdin", baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
                onWriteProgram);
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
        this(null, stdinInput, true, true, stdinRawFormat, "stdin", baseDir, silenceThresholdDb,
                silenceDurationSeconds, outputSampleRate, outputChannels, outputBitDepth,
                onWriteProgram);
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
                           String onWriteProgram) {
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
        this.streamLabel = sanitizeStreamName(initialLabel);
    }

    public void stop() {
        running = false;
        log("STOP", ANSI_YELLOW, "Shutdown requested, finishing current write before exit.");
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
                processStream(din, decodedFormat);
            }
        }
    }

    private void processStream(AudioInputStream din, AudioFormat format) throws IOException {
        int frameSize = format.getFrameSize();
        float frameRate = format.getFrameRate();
        long framesForSilence = (long) (silenceDurationSeconds * frameRate);

        byte[] buffer = new byte[frameSize * 1024];
        ByteArrayOutputStream chunk = new ByteArrayOutputStream();
        long recordedFrames = 0;
        long silentFrames = 0;
        long chunkStartTime = 0;
        boolean activelyRecording = false;

        int n;
        while (running && (n = din.read(buffer)) != -1) {
            boolean isSilent = isSilent(buffer, n, format);
            if (!isSilent) {
                if (chunk.size() == 0) {
                    chunkStartTime = System.currentTimeMillis();
                    log("RECORD", ANSI_GREEN,
                            "Audio detected, starting clip for stream " + streamLabel + ".");
                }
                chunk.write(buffer, 0, n);
                recordedFrames += n / frameSize;
                silentFrames = 0;
                activelyRecording = true;
            } else {
                if (activelyRecording) {
                    chunk.write(buffer, 0, n);
                    recordedFrames += n / frameSize;
                }
                silentFrames += n / frameSize;
                if (silentFrames >= framesForSilence && chunk.size() > 0) {
                    log("SILENCE", ANSI_YELLOW,
                            String.format("Silence reached after %.1f s, closing clip.",
                                    recordedFrames / frameRate));
                    writeChunk(chunk.toByteArray(), format, chunkStartTime);
                    chunk.reset();
                    activelyRecording = false;
                    recordedFrames = 0;
                    silentFrames = 0;
                }
            }
        }
        if (chunk.size() > 0) {
            writeChunk(chunk.toByteArray(), format, chunkStartTime);
            chunk.reset();
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
            log("WRITE", ANSI_GREEN, "Saved clip: " + out);
            runOnWriteProgram(out.toAbsolutePath());
        } catch (IOException ioe) {
            logError("Failed to write chunk: " + ioe.getMessage());
            ioe.printStackTrace(System.err);
        }
    }

    private void runOnWriteProgram(Path wavPath) {
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

    private void updateStreamLabel(HttpURLConnection conn) {
        String headerName = firstNonBlank(
                conn.getHeaderField("icy-name"),
                conn.getHeaderField("x-audiocast-name"),
                conn.getHeaderField("ice-name"),
                streamUrl.getPath());
        this.streamLabel = sanitizeStreamName(headerName);
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
        String candidate = firstNonBlank(value);
        int slashIndex = candidate.lastIndexOf('/');
        if (slashIndex >= 0 && slashIndex < candidate.length() - 1) {
            candidate = candidate.substring(slashIndex + 1);
        }
        candidate = candidate.replaceAll("\\.[A-Za-z0-9]{1,5}$", "");
        candidate = candidate.replaceAll("[^A-Za-z0-9._-]+", "_");
        candidate = candidate.replaceAll("_+", "_");
        candidate = candidate.replaceAll("^_+|_+$", "");
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
        if (supportsAnsi()) {
            System.out.println(color + prefix + ANSI_RESET + message);
        } else {
            System.out.println(prefix + message);
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
