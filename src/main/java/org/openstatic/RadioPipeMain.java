package org.openstatic;

import org.apache.commons.cli.*;
import javax.sound.sampled.AudioFormat;
import javax.sound.sampled.AudioSystem;
import javax.sound.sampled.DataLine;
import javax.sound.sampled.Mixer;
import javax.sound.sampled.SourceDataLine;
import javax.sound.sampled.TargetDataLine;

import java.io.InputStream;
import java.net.InetSocketAddress;
import java.net.URL;
import java.nio.file.FileAlreadyExistsException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.List;

public class RadioPipeMain
{


    public static void main(String[] args)
    {
        CommandLine cmd = null;
        ApiWebSocketServer apiWebSocketServer = null;
        Options options = new Options();
        CommandLineParser parser = new DefaultParser();

        options.addOption(new Option("?", "help", false, "Shows help"));
        options.addOption(Option.builder("u").longOpt("url").hasArg().argName("URL")
            .desc("Shoutcast/Icecast stream URL to record (use exactly one of --url, --stdin, --pipe-input, or --input-dev)").build());
        options.addOption(Option.builder("i").longOpt("stdin")
            .desc("Read audio data from stdin (use exactly one of --url, --stdin, --pipe-input, or --input-dev)").build());
        options.addOption(Option.builder().longOpt("pipe-input").hasArg().argName("COMMAND")
            .desc("Launch command/process and read audio from its stdout (use exactly one of --url, --stdin, --pipe-input, or --input-dev)").build());
        options.addOption(Option.builder().longOpt("pipe-input-raw")
            .desc("Treat --pipe-input stdout as raw PCM bytes instead of containerized audio").build());
        options.addOption(Option.builder().longOpt("pipe-input-rate").hasArg().argName("HZ")
            .desc("Raw pipe input sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("pipe-input-channels").hasArg().argName("N")
            .desc("Raw pipe input channel count (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("pipe-input-bits").hasArg().argName("BITS")
            .desc("Raw pipe input bit depth (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("pipe-input-endian").hasArg().argName("ORDER")
            .desc("Raw pipe input byte order: little or big (default matches --endian)").build());
        options.addOption(Option.builder().longOpt("pipe-input-unsigned")
            .desc("Raw pipe input samples are unsigned PCM (default signed PCM)").build());
        options.addOption(Option.builder().longOpt("stdin-raw")
            .desc("Treat stdin as raw PCM bytes instead of containerized audio").build());
        options.addOption(Option.builder().longOpt("stdin-rate").hasArg().argName("HZ")
            .desc("Raw stdin sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("stdin-channels").hasArg().argName("N")
            .desc("Raw stdin channel count (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("stdin-bits").hasArg().argName("BITS")
            .desc("Raw stdin bit depth (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("stdin-endian").hasArg().argName("ORDER")
            .desc("Raw stdin byte order: little or big (default matches --endian)").build());
        options.addOption(Option.builder().longOpt("stdin-unsigned")
            .desc("Raw stdin samples are unsigned PCM (default signed PCM)").build());
        options.addOption(Option.builder().longOpt("input-dev").hasArg().argName("DEVICE")
            .desc("Read audio from a hardware input device (index from --input-devs or mixer name; use exactly one of --url, --stdin, --pipe-input, or --input-dev)").build());
        options.addOption(Option.builder().longOpt("input-devs")
            .desc("List available hardware input devices and exit").build());
        options.addOption(Option.builder().longOpt("input-dev-sample-rate").hasArg().argName("HZ")
            .desc("Input device capture sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("input-dev-channels").hasArg().argName("N")
            .desc("Input device capture channel count (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("input-dev-bits").hasArg().argName("BITS")
            .desc("Input device capture bit depth (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("input-dev-endian").hasArg().argName("ORDER")
            .desc("Input device capture byte order: little or big (default matches --endian)").build());
        options.addOption(Option.builder().longOpt("input-dejitter").hasArg().argName("MS")
            .desc("Input de-jitter buffer in milliseconds to smooth bursty piped input (default 250)").build());
        options.addOption(Option.builder().longOpt("stdout")
            .desc("Write gated clips to stdout (WAV clip stream by default)").build());
        options.addOption(Option.builder().longOpt("output-dev").hasArg().argName("DEVICE")
            .desc("Play gated audio to a hardware output device (index from --output-devs or mixer name)").build());
        options.addOption(Option.builder().longOpt("output-devs")
            .desc("List available hardware output devices and exit").build());
        options.addOption(Option.builder().longOpt("pipe-output").hasArg().argName("COMMAND")
            .desc("Launch command/process and pipe gated output audio to its stdin (repeatable)").build());
        options.addOption(Option.builder().longOpt("pipe-output-raw")
            .desc("Write gated audio to --pipe-output commands as raw PCM bytes instead of WAV clips").build());
        options.addOption(Option.builder().longOpt("pipe-output-rate").hasArg().argName("HZ")
            .desc("Raw pipe output sample rate in Hz (default matches --sample-rate; applies to following --pipe-output entries)").build());
        options.addOption(Option.builder().longOpt("pipe-output-channels").hasArg().argName("N")
            .desc("Raw pipe output channel count (default matches --channels; applies to following --pipe-output entries)").build());
        options.addOption(Option.builder().longOpt("pipe-output-bits").hasArg().argName("BITS")
            .desc("Raw pipe output bit depth (default matches --bitrate; applies to following --pipe-output entries)").build());
        options.addOption(Option.builder().longOpt("pipe-output-endian").hasArg().argName("ORDER")
            .desc("Raw pipe output byte order: little or big (default matches --endian; applies to following --pipe-output entries)").build());
        options.addOption(Option.builder().longOpt("pipe-output-unsigned")
            .desc("Raw pipe output samples are unsigned PCM (default signed PCM; applies to following --pipe-output entries)").build());
        options.addOption(Option.builder().longOpt("pipe-output-pad")
            .desc("When pipe raw mode is enabled, emit silence to --pipe-output commands while input stream stalls").build());
        options.addOption(Option.builder().longOpt("pipe-output-pad-delay").hasArg().argName("MS")
            .desc("Delay in milliseconds before pipe output pad starts emitting silence (default 500)").build());
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
        options.addOption(Option.builder().longOpt("stdout-endian").hasArg().argName("ORDER")
            .desc("Raw stdout byte order: little or big (default matches --endian)").build());
        options.addOption(Option.builder().longOpt("stdout-unsigned")
            .desc("Raw stdout samples are unsigned PCM (default signed PCM)").build());
        options.addOption(Option.builder("o").longOpt("out").hasArg().argName("DIR")
            .optionalArg(true)
            .desc("Enable Recording, and optionally specify base directory where recordings will be stored (default=$RADIOPIPE_RECORDINGS or ./recordings)").build());
        options.addOption(Option.builder("t").longOpt("threshold").hasArg().argName("DB")
                .desc("Silence threshold in dB (default -50)").build());
        options.addOption(Option.builder("s").longOpt("silence").hasArg().argName("SECONDS")
                .desc("Duration of silence before closing a clip (default 2s)").build());
        options.addOption(Option.builder("r").longOpt("sample-rate").hasArg().argName("HZ")
            .desc("Default sample rate in Hz for recording and raw I/O formats (default 8000)").build());
        options.addOption(Option.builder("c").longOpt("channels").hasArg().argName("N")
            .desc("Default channel count for recording and raw I/O formats (1=mono, 2=stereo) (default 1)").build());
        options.addOption(Option.builder("b").longOpt("bitrate").hasArg().argName("BITS")
            .desc("Default PCM bit depth for recording and raw I/O formats in bits (default 16)").build());
        options.addOption(Option.builder().longOpt("endian").hasArg().argName("ORDER")
            .desc("Default byte order for recording and raw I/O formats: little or big (default little)").build());
        options.addOption(Option.builder().longOpt("recording-sample-rate").hasArg().argName("HZ")
            .desc("Recording output sample rate in Hz (default matches --sample-rate)").build());
        options.addOption(Option.builder().longOpt("recording-channels").hasArg().argName("N")
            .desc("Recording output channels (1=mono, 2=stereo) (default matches --channels)").build());
        options.addOption(Option.builder().longOpt("recording-bitrate").hasArg().argName("BITS")
            .desc("Recording output PCM bit depth in bits (default matches --bitrate)").build());
        options.addOption(Option.builder().longOpt("recording-endian").hasArg().argName("ORDER")
            .desc("Recording output byte order: little or big (default matches --endian)").build());
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
        options.addOption(Option.builder().longOpt("gain").hasArg().argName("DB")
            .desc("Apply fixed post-gate gain in dB before recording/stdout (range -60 to +60, default 0)").build());
        options.addOption(Option.builder().longOpt("voice-filter")
            .desc("Apply post-gate voice band-pass filtering (300-3400 Hz) before gain/output").build());
        options.addOption(Option.builder().longOpt("deemphasis").hasArg().optionalArg(true).argName("TAU")
            .desc("Apply FM de-emphasis filter (default 75 µs for Americas; use 50 for Europe/Asia)").build());
        options.addOption(Option.builder().longOpt("auto-gain")
            .desc("Enable automatic post-gate gain boost toward a target level").build());
        options.addOption(Option.builder().longOpt("api-websocket").hasArg().argName("HOST:PORT")
            .desc("Enable websocket API server at host:port (example: 0.0.0.0:9000)").build());

        try {
            cmd = parser.parse(options, args);

            if (cmd.hasOption("?")) {
                showHelp(options);
            }

            if (cmd.hasOption("output-devs")) {
                listAudioOutputDevices();
                return;
            }

            if (cmd.hasOption("input-devs")) {
                listAudioInputDevices();
                return;
            }

            // gather options
            boolean hasUrl = cmd.hasOption("u");
                boolean usePipeInput = cmd.hasOption("pipe-input");
                String pipeInputCommand = cmd.getOptionValue("pipe-input");
                boolean pipeInputRaw = cmd.hasOption("pipe-input-raw");
            boolean useStdin = cmd.hasOption("i");
            boolean stdinRaw = cmd.hasOption("stdin-raw");
            String inputDeviceSelector = cmd.getOptionValue("input-dev");
            boolean useInputDev = inputDeviceSelector != null;
            boolean hasRawFormatFlags = cmd.hasOption("stdin-rate")
                    || cmd.hasOption("stdin-channels")
                    || cmd.hasOption("stdin-bits")
                    || cmd.hasOption("stdin-endian")
                    || cmd.hasOption("stdin-unsigned");
                boolean hasPipeInputRawFormatFlags = cmd.hasOption("pipe-input-rate")
                    || cmd.hasOption("pipe-input-channels")
                    || cmd.hasOption("pipe-input-bits")
                    || cmd.hasOption("pipe-input-endian")
                    || cmd.hasOption("pipe-input-unsigned");
            boolean useStdout = cmd.hasOption("stdout");
            String outputDeviceSelector = cmd.getOptionValue("output-dev");
            String[] pipeCommands = cmd.getOptionValues("pipe-output");
            boolean usePipeOutput = pipeCommands != null && pipeCommands.length > 0;
            boolean pipeOutputRaw = cmd.hasOption("pipe-output-raw");
            boolean pipeOutputPad = cmd.hasOption("pipe-output-pad");
            long pipeOutputPadDelayMs = Long.parseLong(cmd.getOptionValue("pipe-output-pad-delay", "500"));
            boolean stdoutRaw = cmd.hasOption("stdout-raw");
                boolean stdoutPad = cmd.hasOption("stdout-pad");
                long stdoutPadDelayMs = Long.parseLong(cmd.getOptionValue("stdout-pad-delay", "500"));
                boolean hasPipeRawFormatFlags = cmd.hasOption("pipe-output-rate")
                    || cmd.hasOption("pipe-output-channels")
                    || cmd.hasOption("pipe-output-bits")
                    || cmd.hasOption("pipe-output-endian")
                    || cmd.hasOption("pipe-output-unsigned");
            boolean hasStdoutRawFormatFlags = cmd.hasOption("stdout-rate")
                    || cmd.hasOption("stdout-channels")
                    || cmd.hasOption("stdout-bits")
                    || cmd.hasOption("stdout-endian")
                    || cmd.hasOption("stdout-unsigned");
            if (hasRawFormatFlags || stdinRaw)
            {
                useStdin = true; // if any raw format flags are set, we require stdin input
            }
            if (hasPipeInputRawFormatFlags || pipeInputRaw)
            {
                usePipeInput = true;
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
            if (outputDeviceSelector != null && outputDeviceSelector.trim().isEmpty()) {
                throw new ParseException("output-dev must not be empty; use --output-devs to list devices");
            }
            if (inputDeviceSelector != null && inputDeviceSelector.trim().isEmpty()) {
                throw new ParseException("input-dev must not be empty; use --input-devs to list devices");
            }
            if (usePipeInput && isBlank(pipeInputCommand)) {
                throw new ParseException("pipe-input command must not be empty");
            }
            if (pipeCommands != null) {
                for (String pipeCommand : pipeCommands) {
                    if (isBlank(pipeCommand)) {
                        throw new ParseException("pipe-output command must not be empty");
                    }
                }
            }
            if (hasPipeRawFormatFlags && !pipeOutputRaw) {
                pipeOutputRaw = true;
            }
            if (pipeOutputPad) {
                pipeOutputRaw = true;
            }
            if (cmd.hasOption("pipe-output-pad-delay")) {
                pipeOutputRaw = true;
                pipeOutputPad = true;
            }
            if (hasPipeInputRawFormatFlags && !pipeInputRaw) {
                pipeInputRaw = true;
            }
            if (pipeOutputRaw && (pipeCommands == null || pipeCommands.length == 0)) {
                throw new ParseException("--pipe-output-raw requires at least one --pipe-output command");
            }
            if (pipeInputRaw && !usePipeInput) {
                throw new ParseException("--pipe-input-raw requires --pipe-input <COMMAND>");
            }
            int inputSourceCount = (hasUrl ? 1 : 0) + (useStdin ? 1 : 0) + (usePipeInput ? 1 : 0) + (useInputDev ? 1 : 0);
            if (inputSourceCount != 1) {
                throw new ParseException("Specify exactly one input source: --url <URL>, --stdin, --pipe-input <COMMAND>, or --input-dev <DEVICE>");
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
            boolean useOutputDev = outputDeviceSelector != null;
            boolean outputOnlyMode = (useStdout || usePipeOutput || useOutputDev) && !hasOutDir;
            if (!outputOnlyMode) {
                resolvedRecordingsDirectory = resolveRecordingsDirectory(cmd.getOptionValue("o"));
                outDir = ensureRecordingsDirectory(resolvedRecordingsDirectory.directory);
            }
            logRecordingsDirectorySelection(outDir, resolvedRecordingsDirectory, useStdout, usePipeOutput, useOutputDev, hasOutDir);
            double threshold = Double.parseDouble(cmd.getOptionValue("t", "-50"));
            double silenceSeconds = Double.parseDouble(cmd.getOptionValue("s", "2"));
            float defaultSampleRate = Float.parseFloat(cmd.getOptionValue("sample-rate", "8000"));
            int defaultChannels = Integer.parseInt(cmd.getOptionValue("channels", "1"));
            int defaultBitDepth = Integer.parseInt(cmd.getOptionValue("bitrate", "16"));
            boolean defaultBigEndian = parseEndianOption("endian", cmd.getOptionValue("endian", "little"));
            float outputSampleRate = Float.parseFloat(cmd.getOptionValue("recording-sample-rate", String.valueOf(defaultSampleRate)));
            int outputChannels = Integer.parseInt(cmd.getOptionValue("recording-channels", String.valueOf(defaultChannels)));
            int outputBitDepth = Integer.parseInt(cmd.getOptionValue("recording-bitrate", String.valueOf(defaultBitDepth)));
                boolean outputBigEndian = parseEndianOption("recording-endian",
                    cmd.getOptionValue("recording-endian", defaultBigEndian ? "big" : "little"));
            String onWriteProgram = cmd.getOptionValue("x");
            String streamNameOverride = cmd.getOptionValue("n");
            Integer dcsCode = cmd.hasOption("dcs") ? parseDcsCode(cmd.getOptionValue("dcs")) : null;
            Double ctcssToneHz = cmd.hasOption("ctcss") ? parseCtcssTone(cmd.getOptionValue("ctcss")) : null;
            String apiWebSocketBinding = cmd.getOptionValue("api-websocket");
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
            double gainDb;
            try {
                gainDb = Double.parseDouble(cmd.getOptionValue("gain", "0"));
            } catch (NumberFormatException nfe) {
                throw new ParseException("gain must be a numeric value in dB");
            }
            boolean autoGain = cmd.hasOption("auto-gain");
            boolean voiceFilter = cmd.hasOption("voice-filter");
            boolean deemphasis = cmd.hasOption("deemphasis");
            double deemphasisTau = DeemphasisFilter.TAU_75_US;
            if (deemphasis) {
                String tauArg = cmd.getOptionValue("deemphasis");
                if (tauArg != null) {
                    tauArg = tauArg.trim();
                    try {
                        double parsed = Double.parseDouble(tauArg);
                        if (parsed > 0 && parsed < 1000) {
                            deemphasisTau = parsed * 1.0e-6;
                        } else {
                            throw new ParseException("deemphasis tau must be between 0 and 1000 µs");
                        }
                    } catch (NumberFormatException nfe) {
                        throw new ParseException("deemphasis tau must be a numeric value in µs (e.g. 75 or 50)");
                    }
                }
            }
            AudioFormat pipeInputRawFormat = null;
            AudioFormat stdoutRawFormat = null;
            List<StreamRecorder.PipeOutputTarget> pipeOutputTargets = new ArrayList<>();
            AudioFormat inputDevFormat = null;

            if (defaultSampleRate <= 0) {
                throw new ParseException("sample-rate must be > 0");
            }
            if (defaultChannels <= 0) {
                throw new ParseException("channels must be > 0");
            }
            if (defaultBitDepth <= 0 || defaultBitDepth % 8 != 0) {
                throw new ParseException("bitrate must be a positive multiple of 8 (for PCM bit depth)");
            }

            if (outputSampleRate <= 0) {
                throw new ParseException("recording-sample-rate must be > 0");
            }
            if (outputChannels <= 0) {
                throw new ParseException("recording-channels must be > 0");
            }
            if (outputBitDepth <= 0 || outputBitDepth % 8 != 0) {
                throw new ParseException("recording-bitrate must be a positive multiple of 8 (for PCM bit depth)");
            }
            if (dcsCode != null && outputBitDepth != 16) {
                throw new ParseException("--dcs requires 16-bit output PCM (use --bitrate 16 or --recording-bitrate 16)");
            }
            if (ctcssToneHz != null && outputBitDepth != 16) {
                throw new ParseException("--ctcss requires 16-bit output PCM (use --bitrate 16 or --recording-bitrate 16)");
            }
            if (inputDejitterMs < 0) {
                throw new ParseException("input-dejitter must be >= 0 milliseconds");
            }
            if (gateHoldSeconds < 0) {
                throw new ParseException("gate-hold must be >= 0 seconds");
            }
            if (!Double.isFinite(gainDb)) {
                throw new ParseException("gain must be a finite numeric value in dB");
            }
            if (gainDb < -60.0 || gainDb > 60.0) {
                throw new ParseException("gain must be between -60 and +60 dB");
            }
            if (stdoutPadDelayMs < 0) {
                throw new ParseException("stdout-pad-delay must be >= 0 milliseconds");
            }
            if (pipeOutputPadDelayMs < 0) {
                throw new ParseException("pipe-output-pad-delay must be >= 0 milliseconds");
            }
            if (outDir == null && onWriteProgram != null) {
                throw new ParseException("--on-write requires file recording; provide -o when using --stdout");
            }

            if (!isBlank(apiWebSocketBinding)) {
                InetSocketAddress bindAddress = parseApiWebSocketAddress(apiWebSocketBinding);
                apiWebSocketServer = new ApiWebSocketServer(bindAddress);
                apiWebSocketServer.start();
                System.err.println("startup: websocket api = ws://"
                        + bindAddress.getHostString()
                        + ":"
                        + bindAddress.getPort());
            }

            if (stdoutRaw) {
                float stdoutSampleRate = Float.parseFloat(cmd.getOptionValue("stdout-rate", String.valueOf(defaultSampleRate)));
                int stdoutChannels = Integer.parseInt(cmd.getOptionValue("stdout-channels", String.valueOf(defaultChannels)));
                int stdoutBitDepth = Integer.parseInt(cmd.getOptionValue("stdout-bits", String.valueOf(defaultBitDepth)));
                boolean stdoutBigEndian = resolveEndianOption(cmd, "stdout-endian", defaultBigEndian);
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

            if (usePipeOutput) {
                pipeOutputTargets = parsePipeOutputTargetsInOrder(
                        args,
                        defaultSampleRate,
                        defaultChannels,
                        defaultBitDepth,
                        defaultBigEndian,
                        pipeOutputRaw,
                        pipeOutputPad,
                        pipeOutputPadDelayMs);
            }

            if (pipeInputRaw) {
                float pipeInputSampleRate = Float.parseFloat(cmd.getOptionValue("pipe-input-rate", String.valueOf(defaultSampleRate)));
                int pipeInputChannels = Integer.parseInt(cmd.getOptionValue("pipe-input-channels", String.valueOf(defaultChannels)));
                int pipeInputBitDepth = Integer.parseInt(cmd.getOptionValue("pipe-input-bits", String.valueOf(defaultBitDepth)));
                boolean pipeInputBigEndian = resolveEndianOption(cmd, "pipe-input-endian", defaultBigEndian);
                boolean pipeInputUnsigned = cmd.hasOption("pipe-input-unsigned");

                if (pipeInputSampleRate <= 0) {
                    throw new ParseException("pipe-input-rate must be > 0");
                }
                if (pipeInputChannels <= 0) {
                    throw new ParseException("pipe-input-channels must be > 0");
                }
                if (pipeInputBitDepth <= 0 || pipeInputBitDepth % 8 != 0) {
                    throw new ParseException("pipe-input-bits must be a positive multiple of 8");
                }

                AudioFormat.Encoding pipeInputEncoding = pipeInputUnsigned
                        ? AudioFormat.Encoding.PCM_UNSIGNED
                        : AudioFormat.Encoding.PCM_SIGNED;
                pipeInputRawFormat = new AudioFormat(
                        pipeInputEncoding,
                        pipeInputSampleRate,
                        pipeInputBitDepth,
                        pipeInputChannels,
                        pipeInputChannels * (pipeInputBitDepth / 8),
                        pipeInputSampleRate,
                        pipeInputBigEndian);
            }

            if (useInputDev) {
                float idSampleRate = Float.parseFloat(cmd.getOptionValue("input-dev-sample-rate", String.valueOf(defaultSampleRate)));
                int idChannels = Integer.parseInt(cmd.getOptionValue("input-dev-channels", String.valueOf(defaultChannels)));
                int idBitDepth = Integer.parseInt(cmd.getOptionValue("input-dev-bits", String.valueOf(defaultBitDepth)));
                boolean idBigEndian = resolveEndianOption(cmd, "input-dev-endian", defaultBigEndian);

                if (idSampleRate <= 0) {
                    throw new ParseException("input-dev-sample-rate must be > 0");
                }
                if (idChannels <= 0) {
                    throw new ParseException("input-dev-channels must be > 0");
                }
                if (idBitDepth <= 0 || idBitDepth % 8 != 0) {
                    throw new ParseException("input-dev-bits must be a positive multiple of 8");
                }

                inputDevFormat = new AudioFormat(
                        AudioFormat.Encoding.PCM_SIGNED,
                        idSampleRate,
                        idBitDepth,
                        idChannels,
                        idChannels * (idBitDepth / 8),
                        idSampleRate,
                        idBigEndian);
            }

            StreamRecorder recorder;
            if (useInputDev) {
                recorder = new StreamRecorder(
                        (InputStream) null,
                        outDir,
                        threshold,
                        silenceSeconds,
                        outputSampleRate,
                        outputChannels,
                        outputBitDepth,
                        outputBigEndian,
                        onWriteProgram,
                        streamNameOverride);
            } else if (useStdin || usePipeInput) {
                if (stdinRaw) {
                    float stdinSampleRate = Float.parseFloat(cmd.getOptionValue("stdin-rate", String.valueOf(defaultSampleRate)));
                    int stdinChannels = Integer.parseInt(cmd.getOptionValue("stdin-channels", String.valueOf(defaultChannels)));
                    int stdinBitDepth = Integer.parseInt(cmd.getOptionValue("stdin-bits", String.valueOf(defaultBitDepth)));
                    boolean stdinBigEndian = resolveEndianOption(cmd, "stdin-endian", defaultBigEndian);
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
                            outputBigEndian,
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
                            outputBigEndian,
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
                        outputBigEndian,
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
            recorder.setVoiceFilterEnabled(voiceFilter);
            if (deemphasis) {
                recorder.setDeemphasis(true, deemphasisTau);
            }
            recorder.setGainControl(gainDb, autoGain);
            recorder.setStdoutOutput(useStdout, stdoutRaw, stdoutRawFormat, stdoutPad, stdoutPadDelayMs);
            recorder.setDeviceOutput(outputDeviceSelector);
            recorder.setDeviceInput(useInputDev ? inputDeviceSelector : null, inputDevFormat);
            recorder.setPipeOutputs(pipeOutputTargets);
            recorder.setPipeInput(usePipeInput ? pipeInputCommand : null, pipeInputRaw, pipeInputRawFormat);
            recorder.setApiWebSocketServer(apiWebSocketServer);
            final ApiWebSocketServer shutdownApiWebSocketServer = apiWebSocketServer;
            Runtime.getRuntime().addShutdownHook(new Thread(() -> {
                recorder.stop();
                if (shutdownApiWebSocketServer != null) {
                    shutdownApiWebSocketServer.shutdownQuietly();
                }
            }));
            recorder.run();

        } catch (ParseException e) {
            System.err.println("error parsing command line: " + e.getMessage());
            showHelp(options);
        } catch (Exception e) {
            System.err.println("fatal: " + e);
            e.printStackTrace(System.err);
        } finally {
            if (apiWebSocketServer != null) {
                apiWebSocketServer.shutdownQuietly();
            }
        }
    }

    private static void listAudioOutputDevices()
    {
        Mixer.Info[] mixerInfos = AudioSystem.getMixerInfo();
        int listed = 0;
        for (int i = 0; i < mixerInfos.length; i++) {
            Mixer.Info info = mixerInfos[i];
            Mixer mixer = AudioSystem.getMixer(info);
            DataLine.Info speakerLine = new DataLine.Info(SourceDataLine.class, null);
            if (!mixer.isLineSupported(speakerLine)) {
                continue;
            }

            listed++;
            System.out.println(i + ": " + info.getName());
            if (!isBlank(info.getDescription())) {
                System.out.println("    description: " + info.getDescription());
            }
            if (!isBlank(info.getVendor())) {
                System.out.println("    vendor: " + info.getVendor());
            }
            if (!isBlank(info.getVersion())) {
                System.out.println("    version: " + info.getVersion());
            }
        }

        if (listed == 0) {
            System.out.println("No hardware audio output devices were detected by Java Sound.");
            return;
        }

        System.out.println();
        System.out.println("Use --output-dev <index> or --output-dev <name-substring> to select a device.");
    }

    private static void listAudioInputDevices()
    {
        Mixer.Info[] mixerInfos = AudioSystem.getMixerInfo();
        int listed = 0;
        for (int i = 0; i < mixerInfos.length; i++) {
            Mixer.Info info = mixerInfos[i];
            Mixer mixer = AudioSystem.getMixer(info);
            DataLine.Info micLine = new DataLine.Info(TargetDataLine.class, null);
            if (!mixer.isLineSupported(micLine)) {
                continue;
            }

            listed++;
            System.out.println(i + ": " + info.getName());
            if (!isBlank(info.getDescription())) {
                System.out.println("    description: " + info.getDescription());
            }
            if (!isBlank(info.getVendor())) {
                System.out.println("    vendor: " + info.getVendor());
            }
            if (!isBlank(info.getVersion())) {
                System.out.println("    version: " + info.getVersion());
            }
        }

        if (listed == 0) {
            System.out.println("No hardware audio input devices were detected by Java Sound.");
            return;
        }

        System.out.println();
        System.out.println("Use --input-dev <index> or --input-dev <name-substring> to select a device.");
    }

    private static InetSocketAddress parseApiWebSocketAddress(String bindingValue) throws ParseException
    {
        if (isBlank(bindingValue)) {
            throw new ParseException("api-websocket must be in host:port format");
        }

        String normalized = bindingValue.trim();
        int separator = normalized.lastIndexOf(':');
        if (separator <= 0 || separator == normalized.length() - 1) {
            throw new ParseException("api-websocket must be in host:port format (example: 0.0.0.0:9000)");
        }

        String host = normalized.substring(0, separator).trim();
        String portText = normalized.substring(separator + 1).trim();

        if (host.isEmpty()) {
            throw new ParseException("api-websocket host must not be empty");
        }

        int port;
        try {
            port = Integer.parseInt(portText);
        } catch (NumberFormatException nfe) {
            throw new ParseException("api-websocket port must be an integer between 1 and 65535");
        }

        if (port < 1 || port > 65535) {
            throw new ParseException("api-websocket port must be between 1 and 65535");
        }

        return new InetSocketAddress(host, port);
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

    private static boolean parseEndianOption(String optionName, String value) throws ParseException
    {
        if (value == null) {
            throw new ParseException(optionName + " must be little or big");
        }

        String normalized = value.trim().toLowerCase();
        if (normalized.isEmpty()) {
            throw new ParseException(optionName + " must be little or big");
        }

        if ("little".equals(normalized) || "le".equals(normalized)) {
            return false;
        }
        if ("big".equals(normalized) || "be".equals(normalized)) {
            return true;
        }

        throw new ParseException(optionName + " must be little or big");
    }

    private static boolean resolveEndianOption(CommandLine cmd,
                                               String optionName,
                                               boolean defaultBigEndian) throws ParseException
    {
        String explicitValue = cmd.getOptionValue(optionName);
        if (explicitValue == null) {
            return defaultBigEndian;
        }

        return parseEndianOption(optionName, explicitValue);
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
                                                        boolean usePipeOutput,
                                                        boolean useOutputDev,
                                                        boolean hasOutDir)
    {
        if (outDir == null) {
            if ((useStdout || usePipeOutput || useOutputDev) && !hasOutDir) {
                StringBuilder modes = new StringBuilder();
                if (useStdout) modes.append("--stdout");
                if (usePipeOutput) { if (modes.length() > 0) modes.append("/"); modes.append("--pipe-output"); }
                if (useOutputDev) { if (modes.length() > 0) modes.append("/"); modes.append("--output-dev"); }
                System.err.println("startup: file recording disabled (output-only mode: " + modes + " without -o)");
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

    private static List<StreamRecorder.PipeOutputTarget> parsePipeOutputTargetsInOrder(String[] args,
                                                                                       float defaultSampleRate,
                                                                                       int defaultChannels,
                                                                                       int defaultBitDepth,
                                                                                       boolean defaultBigEndian,
                                                                                       boolean defaultRawMode,
                                                                                       boolean defaultPadMode,
                                                                                       long defaultPadDelayMillis) throws ParseException {
        List<StreamRecorder.PipeOutputTarget> targets = new ArrayList<>();
        float currentRate = defaultSampleRate;
        int currentChannels = defaultChannels;
        int currentBits = defaultBitDepth;
        boolean currentBigEndian = defaultBigEndian;
        boolean currentUnsigned = false;
        boolean currentRaw = defaultRawMode;
        boolean currentPad = defaultPadMode;
        long currentPadDelayMillis = defaultPadDelayMillis;

        for (int i = 0; i < args.length; i++) {
            String token = args[i];
            if (token == null || token.isEmpty()) {
                continue;
            }

            String inlineValue = null;
            int equalsIndex = token.indexOf('=');
            if (equalsIndex > 0) {
                inlineValue = token.substring(equalsIndex + 1);
                token = token.substring(0, equalsIndex);
            }

            switch (token) {
                case "--pipe-output": {
                    String commandValue = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    if (isBlank(commandValue)) {
                        throw new ParseException("pipe-output command must not be empty");
                    }
                    targets.add(buildPipeOutputTarget(
                            commandValue,
                            currentRaw,
                            currentRate,
                            currentChannels,
                            currentBits,
                            currentBigEndian,
                            currentUnsigned,
                            currentPad,
                            currentPadDelayMillis));
                    break;
                }
                case "--pipe-output-raw":
                    currentRaw = true;
                    break;
                case "--pipe-output-pad":
                    currentPad = true;
                    currentRaw = true;
                    break;
                case "--pipe-output-pad-delay": {
                    String value = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    if (isBlank(value)) {
                        throw new ParseException("pipe-output-pad-delay requires a value in milliseconds");
                    }
                    try {
                        currentPadDelayMillis = Long.parseLong(value);
                    } catch (NumberFormatException nfe) {
                        throw new ParseException("pipe-output-pad-delay must be >= 0 milliseconds");
                    }
                    if (currentPadDelayMillis < 0L) {
                        throw new ParseException("pipe-output-pad-delay must be >= 0 milliseconds");
                    }
                    currentPad = true;
                    currentRaw = true;
                    break;
                }
                case "--pipe-output-rate": {
                    String value = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    if (isBlank(value)) {
                        throw new ParseException("pipe-output-rate must be > 0");
                    }
                    try {
                        currentRate = Float.parseFloat(value);
                    } catch (NumberFormatException nfe) {
                        throw new ParseException("pipe-output-rate must be > 0");
                    }
                    if (currentRate <= 0.0f) {
                        throw new ParseException("pipe-output-rate must be > 0");
                    }
                    currentRaw = true;
                    break;
                }
                case "--pipe-output-channels": {
                    String value = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    if (isBlank(value)) {
                        throw new ParseException("pipe-output-channels must be > 0");
                    }
                    try {
                        currentChannels = Integer.parseInt(value);
                    } catch (NumberFormatException nfe) {
                        throw new ParseException("pipe-output-channels must be > 0");
                    }
                    if (currentChannels <= 0) {
                        throw new ParseException("pipe-output-channels must be > 0");
                    }
                    currentRaw = true;
                    break;
                }
                case "--pipe-output-bits": {
                    String value = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    if (isBlank(value)) {
                        throw new ParseException("pipe-output-bits must be a positive multiple of 8");
                    }
                    try {
                        currentBits = Integer.parseInt(value);
                    } catch (NumberFormatException nfe) {
                        throw new ParseException("pipe-output-bits must be a positive multiple of 8");
                    }
                    if (currentBits <= 0 || currentBits % 8 != 0) {
                        throw new ParseException("pipe-output-bits must be a positive multiple of 8");
                    }
                    currentRaw = true;
                    break;
                }
                case "--pipe-output-endian": {
                    String value = (inlineValue != null) ? inlineValue : ((i + 1) < args.length ? args[++i] : null);
                    currentBigEndian = parseEndianOption("pipe-output-endian", value);
                    currentRaw = true;
                    break;
                }
                case "--pipe-output-unsigned":
                    currentUnsigned = true;
                    currentRaw = true;
                    break;
                default:
                    break;
            }
        }

        return targets;
    }

    private static StreamRecorder.PipeOutputTarget buildPipeOutputTarget(String command,
                                                                         boolean rawMode,
                                                                         float sampleRate,
                                                                         int channels,
                                                                         int bitDepth,
                                                                         boolean bigEndian,
                                                                         boolean unsigned,
                                                                         boolean padMode,
                                                                         long padDelayMillis) throws ParseException {
        String normalizedCommand = command.trim();
        if (normalizedCommand.isEmpty()) {
            throw new ParseException("pipe-output command must not be empty");
        }

        if (!rawMode) {
            return StreamRecorder.PipeOutputTarget.wav(normalizedCommand);
        }

        if (sampleRate <= 0.0f) {
            throw new ParseException("pipe-output-rate must be > 0");
        }
        if (channels <= 0) {
            throw new ParseException("pipe-output-channels must be > 0");
        }
        if (bitDepth <= 0 || bitDepth % 8 != 0) {
            throw new ParseException("pipe-output-bits must be a positive multiple of 8");
        }

        AudioFormat.Encoding encoding = unsigned
                ? AudioFormat.Encoding.PCM_UNSIGNED
                : AudioFormat.Encoding.PCM_SIGNED;
        AudioFormat rawFormat = new AudioFormat(
                encoding,
                sampleRate,
                bitDepth,
                channels,
                channels * (bitDepth / 8),
                sampleRate,
                bigEndian);

        return StreamRecorder.PipeOutputTarget.raw(
                normalizedCommand,
                rawFormat,
                padMode,
                Math.max(0L, padDelayMillis));
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
        System.err.println("Developed by KC1TCD with contributions from the open source community.");
        System.exit(0);
    }
}
