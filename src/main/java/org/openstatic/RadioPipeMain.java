package org.openstatic;

import org.apache.commons.cli.*;
import javax.sound.sampled.AudioFormat;

import java.net.URL;
import java.nio.file.FileAlreadyExistsException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;

public class RadioPipeMain
{


    public static void main(String[] args)
    {
        CommandLine cmd = null;
        Options options = new Options();
        CommandLineParser parser = new DefaultParser();

        options.addOption(new Option("?", "help", false, "Shows help"));
        options.addOption(Option.builder("u").longOpt("url").hasArg().argName("URL")
            .desc("Shoutcast/Icecast stream URL to record (use exactly one of --url or --stdin)").build());
        options.addOption(Option.builder("i").longOpt("stdin")
            .desc("Read audio data from stdin (use exactly one of --url or --stdin)").build());
        options.addOption(Option.builder().longOpt("stdin-raw")
            .desc("Treat stdin as raw PCM bytes instead of containerized audio").build());
        options.addOption(Option.builder().longOpt("stdin-rate").hasArg().argName("HZ")
            .desc("Raw stdin sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("stdin-channels").hasArg().argName("N")
            .desc("Raw stdin channel count (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("stdin-bits").hasArg().argName("BITS")
            .desc("Raw stdin bit depth (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("stdin-big-endian")
            .desc("Raw stdin byte order is big-endian (default little-endian)").build());
        options.addOption(Option.builder().longOpt("stdin-unsigned")
            .desc("Raw stdin samples are unsigned PCM (default signed PCM)").build());
        options.addOption(Option.builder().longOpt("input-dejitter").hasArg().argName("MS")
            .desc("Input de-jitter buffer in milliseconds to smooth bursty piped input (default 250)").build());
        options.addOption(Option.builder().longOpt("stdout")
            .desc("Write gated clips to stdout (WAV clip stream by default)").build());
        options.addOption(Option.builder().longOpt("stdout-raw")
            .desc("Write gated audio to stdout as raw PCM bytes").build());
        options.addOption(Option.builder().longOpt("stdout-pad")
            .desc("When stdout raw mode is enabled, emit silence while input stream stalls").build());
        options.addOption(Option.builder().longOpt("stdout-pad-delay").hasArg().argName("MS")
            .desc("Delay in milliseconds before stdout pad starts emitting silence (default 500)").build());
        options.addOption(Option.builder().longOpt("stdout-rate").hasArg().argName("HZ")
            .desc("Raw stdout sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("stdout-channels").hasArg().argName("N")
            .desc("Raw stdout channel count (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("stdout-bits").hasArg().argName("BITS")
            .desc("Raw stdout bit depth (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("stdout-big-endian")
            .desc("Raw stdout byte order is big-endian (default little-endian)").build());
        options.addOption(Option.builder().longOpt("stdout-unsigned")
            .desc("Raw stdout samples are unsigned PCM (default signed PCM)").build());
        options.addOption(Option.builder("o").longOpt("out").hasArg().argName("DIR")
            .optionalArg(true)
            .desc("Base directory where recordings will be stored (default=$RADIOPIPE_RECORDINGS or ./recordings)").build());
        options.addOption(Option.builder("t").longOpt("threshold").hasArg().argName("DB")
                .desc("Silence threshold in dB (default -50)").build());
        options.addOption(Option.builder("s").longOpt("silence").hasArg().argName("SECONDS")
                .desc("Duration of silence before closing a clip (default 2s)").build());
        options.addOption(Option.builder("r").longOpt("sample-rate").hasArg().argName("HZ")
            .desc("Output sample rate in Hz (default 8000)").build());
        options.addOption(Option.builder("c").longOpt("channels").hasArg().argName("N")
            .desc("Output channels (1=mono, 2=stereo) (default 1)").build());
        options.addOption(Option.builder("b").longOpt("bitrate").hasArg().argName("BITS")
            .desc("Output PCM bit depth in bits (default 16)").build());
        options.addOption(Option.builder("x").longOpt("on-write").hasArg().argName("PROGRAM")
            .desc("Optional hook command run after each WAV write; use {wav} placeholder or WAV is arg1 by default").build());
        options.addOption(Option.builder("n").longOpt("name").hasArg().argName("STREAM")
            .desc("Optional stream name override used in filenames (default comes from stream metadata)").build());
        options.addOption(Option.builder().longOpt("dcs").hasArg().argName("CODE")
            .desc("Optional DCS code gate (octal, e.g. 023); clips only while matching code is present").build());
        options.addOption(Option.builder().longOpt("ctcss").hasArg().argName("HZ")
            .desc("Optional CTCSS tone gate in Hz (example: 100.0); clips only while matching tone is present").build());
        options.addOption(Option.builder().longOpt("gate-hold").hasArg().argName("SECONDS")
            .desc("Hold DCS/CTCSS gates open for this many seconds after detection drops (default 0)").build());

        try {
            cmd = parser.parse(options, args);

            if (cmd.hasOption("?")) {
                showHelp(options);
            }

            // gather options
            boolean hasUrl = cmd.hasOption("u");
            boolean useStdin = cmd.hasOption("i");
            boolean stdinRaw = cmd.hasOption("stdin-raw");
            boolean hasRawFormatFlags = cmd.hasOption("stdin-rate")
                    || cmd.hasOption("stdin-channels")
                    || cmd.hasOption("stdin-bits")
                    || cmd.hasOption("stdin-big-endian")
                    || cmd.hasOption("stdin-unsigned");
            boolean useStdout = cmd.hasOption("stdout");
            boolean stdoutRaw = cmd.hasOption("stdout-raw");
                boolean stdoutPad = cmd.hasOption("stdout-pad");
                long stdoutPadDelayMs = Long.parseLong(cmd.getOptionValue("stdout-pad-delay", "500"));
            boolean hasStdoutRawFormatFlags = cmd.hasOption("stdout-rate")
                    || cmd.hasOption("stdout-channels")
                    || cmd.hasOption("stdout-bits")
                    || cmd.hasOption("stdout-big-endian")
                    || cmd.hasOption("stdout-unsigned");
            if (hasRawFormatFlags || stdinRaw)
            {
                useStdin = true; // if any raw format flags are set, we require stdin input
            }
            if (hasStdoutRawFormatFlags || stdoutRaw)
            {
                useStdout = true; // stdout raw flags imply stdout output
            }
            if (stdoutPad)
            {
                useStdout = true;
                stdoutRaw = true; // padding is only meaningful for raw byte stream output
            }
            if (cmd.hasOption("stdout-pad-delay"))
            {
                useStdout = true;
                stdoutRaw = true;
                stdoutPad = true;
            }
            if (hasUrl == useStdin) {
                throw new ParseException("Specify exactly one input source: --url <URL> or --stdin");
            }
            if (hasRawFormatFlags && !stdinRaw) {
                stdinRaw = true; // if any raw format flags are set, we assume raw mode
            }
            if (hasStdoutRawFormatFlags && !stdoutRaw) {
                stdoutRaw = true; // if any stdout raw format flags are set, we assume stdout raw mode
            }

            URL url = hasUrl ? new URL(cmd.getOptionValue("u")) : null;
            boolean hasOutDir = cmd.hasOption("o");
            Path outDir = null;
            ResolvedRecordingsDirectory resolvedRecordingsDirectory = null;
            if (!useStdout || hasOutDir) {
                resolvedRecordingsDirectory = resolveRecordingsDirectory(cmd.getOptionValue("o"));
                outDir = ensureRecordingsDirectory(resolvedRecordingsDirectory.directory);
            }
            logRecordingsDirectorySelection(outDir, resolvedRecordingsDirectory, useStdout, hasOutDir);
            double threshold = Double.parseDouble(cmd.getOptionValue("t", "-50"));
            double silenceSeconds = Double.parseDouble(cmd.getOptionValue("s", "2"));
            float outputSampleRate = Float.parseFloat(cmd.getOptionValue("r", "8000"));
            int outputChannels = Integer.parseInt(cmd.getOptionValue("c", "1"));
            int outputBitDepth = Integer.parseInt(cmd.getOptionValue("b", "16"));
            String onWriteProgram = cmd.getOptionValue("x");
            String streamNameOverride = cmd.getOptionValue("n");
            Integer dcsCode = cmd.hasOption("dcs") ? parseDcsCode(cmd.getOptionValue("dcs")) : null;
            Double ctcssToneHz = cmd.hasOption("ctcss") ? parseCtcssTone(cmd.getOptionValue("ctcss")) : null;
            long inputDejitterMs;
            try {
                inputDejitterMs = Long.parseLong(cmd.getOptionValue("input-dejitter", "250"));
            } catch (NumberFormatException nfe) {
                throw new ParseException("input-dejitter must be an integer number of milliseconds");
            }
            double gateHoldSeconds;
            try {
                gateHoldSeconds = Double.parseDouble(cmd.getOptionValue("gate-hold", "0"));
            } catch (NumberFormatException nfe) {
                throw new ParseException("gate-hold must be a numeric value in seconds");
            }
            AudioFormat stdoutRawFormat = null;

            if (outputSampleRate <= 0) {
                throw new ParseException("sample-rate must be > 0");
            }
            if (outputChannels <= 0) {
                throw new ParseException("channels must be > 0");
            }
            if (outputBitDepth <= 0 || outputBitDepth % 8 != 0) {
                throw new ParseException("bitrate must be a positive multiple of 8 (for PCM bit depth)");
            }
            if (dcsCode != null && outputBitDepth != 16) {
                throw new ParseException("--dcs requires 16-bit output PCM (use --bitrate 16)");
            }
            if (ctcssToneHz != null && outputBitDepth != 16) {
                throw new ParseException("--ctcss requires 16-bit output PCM (use --bitrate 16)");
            }
            if (inputDejitterMs < 0) {
                throw new ParseException("input-dejitter must be >= 0 milliseconds");
            }
            if (gateHoldSeconds < 0) {
                throw new ParseException("gate-hold must be >= 0 seconds");
            }
            if (stdoutPadDelayMs < 0) {
                throw new ParseException("stdout-pad-delay must be >= 0 milliseconds");
            }
            if (outDir == null && onWriteProgram != null) {
                throw new ParseException("--on-write requires file recording; provide -o when using --stdout");
            }

            if (stdoutRaw) {
                float stdoutSampleRate = Float.parseFloat(cmd.getOptionValue("stdout-rate", String.valueOf(outputSampleRate)));
                int stdoutChannels = Integer.parseInt(cmd.getOptionValue("stdout-channels", String.valueOf(outputChannels)));
                int stdoutBitDepth = Integer.parseInt(cmd.getOptionValue("stdout-bits", String.valueOf(outputBitDepth)));
                boolean stdoutBigEndian = cmd.hasOption("stdout-big-endian");
                boolean stdoutUnsigned = cmd.hasOption("stdout-unsigned");

                if (stdoutSampleRate <= 0) {
                    throw new ParseException("stdout-rate must be > 0");
                }
                if (stdoutChannels <= 0) {
                    throw new ParseException("stdout-channels must be > 0");
                }
                if (stdoutBitDepth <= 0 || stdoutBitDepth % 8 != 0) {
                    throw new ParseException("stdout-bits must be a positive multiple of 8");
                }

                AudioFormat.Encoding stdoutEncoding = stdoutUnsigned
                        ? AudioFormat.Encoding.PCM_UNSIGNED
                        : AudioFormat.Encoding.PCM_SIGNED;
                stdoutRawFormat = new AudioFormat(
                        stdoutEncoding,
                        stdoutSampleRate,
                        stdoutBitDepth,
                        stdoutChannels,
                        stdoutChannels * (stdoutBitDepth / 8),
                        stdoutSampleRate,
                        stdoutBigEndian);
            }

            StreamRecorder recorder;
            if (useStdin) {
                if (stdinRaw) {
                    float stdinSampleRate = Float.parseFloat(cmd.getOptionValue("stdin-rate", String.valueOf(outputSampleRate)));
                    int stdinChannels = Integer.parseInt(cmd.getOptionValue("stdin-channels", String.valueOf(outputChannels)));
                    int stdinBitDepth = Integer.parseInt(cmd.getOptionValue("stdin-bits", String.valueOf(outputBitDepth)));
                    boolean stdinBigEndian = cmd.hasOption("stdin-big-endian");
                    boolean stdinUnsigned = cmd.hasOption("stdin-unsigned");

                    if (stdinSampleRate <= 0) {
                        throw new ParseException("stdin-rate must be > 0");
                    }
                    if (stdinChannels <= 0) {
                        throw new ParseException("stdin-channels must be > 0");
                    }
                    if (stdinBitDepth <= 0 || stdinBitDepth % 8 != 0) {
                        throw new ParseException("stdin-bits must be a positive multiple of 8");
                    }

                    AudioFormat.Encoding stdinEncoding = stdinUnsigned
                            ? AudioFormat.Encoding.PCM_UNSIGNED
                            : AudioFormat.Encoding.PCM_SIGNED;
                    AudioFormat stdinFormat = new AudioFormat(
                            stdinEncoding,
                            stdinSampleRate,
                            stdinBitDepth,
                            stdinChannels,
                            stdinChannels * (stdinBitDepth / 8),
                            stdinSampleRate,
                            stdinBigEndian);

                    recorder = new StreamRecorder(
                            System.in,
                            stdinFormat,
                            outDir,
                            threshold,
                            silenceSeconds,
                            outputSampleRate,
                            outputChannels,
                            outputBitDepth,
                            onWriteProgram,
                            streamNameOverride);
                } else {
                    recorder = new StreamRecorder(
                            System.in,
                            outDir,
                            threshold,
                            silenceSeconds,
                            outputSampleRate,
                            outputChannels,
                            outputBitDepth,
                            onWriteProgram,
                            streamNameOverride);
                }
            } else {
                recorder = new StreamRecorder(
                        url,
                        outDir,
                        threshold,
                        silenceSeconds,
                        outputSampleRate,
                        outputChannels,
                        outputBitDepth,
                        onWriteProgram,
                        streamNameOverride);
            }

            if (dcsCode != null) {
                recorder.setRequiredDcsCode(dcsCode);
            }
            if (ctcssToneHz != null) {
                recorder.setRequiredCtcssTone(ctcssToneHz);
            }
            recorder.setInputDejitterMillis(inputDejitterMs);
            recorder.setGateHoldSeconds(gateHoldSeconds);
            recorder.setStdoutOutput(useStdout, stdoutRaw, stdoutRawFormat, stdoutPad, stdoutPadDelayMs);
            Runtime.getRuntime().addShutdownHook(new Thread(recorder::stop));
            recorder.run();

        } catch (ParseException e) {
            System.err.println("error parsing command line: " + e.getMessage());
            showHelp(options);
        } catch (Exception e) {
            System.err.println("fatal: " + e);
            e.printStackTrace(System.err);
        }
    }

    private static int parseDcsCode(String dcsValue) throws ParseException
    {
        if (dcsValue == null || dcsValue.trim().isEmpty()) {
            throw new ParseException("dcs code is required when --dcs is used");
        }

        String normalized = dcsValue.trim();
        if (!normalized.matches("[0-7]{1,3}")) {
            throw new ParseException("dcs must be 1-3 octal digits (example: 023)");
        }

        int parsed = Integer.parseInt(normalized, 8);
        if (parsed < 0 || parsed > 0x1FF) {
            throw new ParseException("dcs code is out of range");
        }

        return parsed;
    }

    private static double parseCtcssTone(String toneValue) throws ParseException
    {
        if (toneValue == null || toneValue.trim().isEmpty()) {
            throw new ParseException("ctcss tone is required when --ctcss is used");
        }

        final double parsed;
        try {
            parsed = Double.parseDouble(toneValue.trim());
        } catch (NumberFormatException nfe) {
            throw new ParseException("ctcss must be a numeric tone in Hz (example: 100.0)");
        }

        if (parsed < 50.0 || parsed > 300.0) {
            throw new ParseException("ctcss tone must be between 50.0 and 300.0 Hz");
        }

        return parsed;
    }

    private static ResolvedRecordingsDirectory resolveRecordingsDirectory(String requestedOutDir)
    {
        if (!isBlank(requestedOutDir)) {
            return new ResolvedRecordingsDirectory(requestedOutDir.trim(), "command line option -o/--out");
        }

        String envOutDir = System.getenv("RADIOPIPE_RECORDINGS");
        if (!isBlank(envOutDir)) {
            return new ResolvedRecordingsDirectory(envOutDir.trim(), "environment variable RADIOPIPE_RECORDINGS");
        }
        return new ResolvedRecordingsDirectory("./recordings", "built-in default");
    }

    private static Path ensureRecordingsDirectory(String configuredDirectory) throws ParseException, java.io.IOException
    {
        Path outDir = Paths.get(configuredDirectory).toAbsolutePath().normalize();

        if (Files.exists(outDir)) {
            if (!Files.isDirectory(outDir)) {
                throw new ParseException("recordings path exists but is not a directory: " + outDir);
            }
            return outDir;
        }

        try {
            Files.createDirectories(outDir);
        } catch (FileAlreadyExistsException faee) {
            if (!Files.isDirectory(outDir)) {
                throw new ParseException("recordings path exists but is not a directory: " + outDir);
            }
        }

        return outDir;
    }

    private static void logRecordingsDirectorySelection(Path outDir,
                                                        ResolvedRecordingsDirectory resolvedRecordingsDirectory,
                                                        boolean useStdout,
                                                        boolean hasOutDir)
    {
        if (outDir == null) {
            if (useStdout && !hasOutDir) {
                System.err.println("startup: file recording disabled (stdout-only mode: --stdout without -o)");
            } else {
                System.err.println("startup: file recording disabled (no recordings directory configured)");
            }
            return;
        }

        String source = (resolvedRecordingsDirectory == null)
                ? "unknown"
                : resolvedRecordingsDirectory.source;
        System.err.println("startup: recordings directory = "
                + outDir.toAbsolutePath().normalize()
                + " (source: "
                + source
                + ")");
    }

    private static boolean isBlank(String value)
    {
        return value == null || value.trim().isEmpty();
    }

    private static final class ResolvedRecordingsDirectory {
        private final String directory;
        private final String source;

        private ResolvedRecordingsDirectory(String directory, String source) {
            this.directory = directory;
            this.source = source;
        }
    }

    public static void showHelp(Options options)
    {
        HelpFormatter formatter = new HelpFormatter();
        formatter.printHelp( "radio-pipe", "RadioPipe: record shoutcast/icecast streams or stdin audio", options, "" );
        System.err.println("For more information please visit https://openstatic.org/projects/radio-pipe");
        System.exit(0);
    }
}
