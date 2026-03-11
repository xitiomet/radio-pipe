package org.openstatic;

import org.apache.commons.cli.*;
import javax.sound.sampled.AudioFormat;

import java.net.URL;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;

public class RMSCastRecorderMain
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
        options.addOption(Option.builder("o").longOpt("out").hasArg().argName("DIR")
            .desc("Base directory where recordings will be stored (default=./recordings)").build());
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
            if (hasRawFormatFlags || stdinRaw)
            {
                useStdin = true; // if any raw format flags are set, we require stdin input
            }
            if (hasUrl == useStdin) {
                throw new ParseException("Specify exactly one input source: --url <URL> or --stdin");
            }
            if (hasRawFormatFlags && !stdinRaw) {
                stdinRaw = true; // if any raw format flags are set, we assume raw mode
            }

            URL url = hasUrl ? new URL(cmd.getOptionValue("u")) : null;
            Path outDir = Paths.get(cmd.getOptionValue("o", "./recordings"));
            Files.createDirectories(outDir);
            double threshold = Double.parseDouble(cmd.getOptionValue("t", "-50"));
            double silenceSeconds = Double.parseDouble(cmd.getOptionValue("s", "2"));
            float outputSampleRate = Float.parseFloat(cmd.getOptionValue("r", "8000"));
            int outputChannels = Integer.parseInt(cmd.getOptionValue("c", "1"));
            int outputBitDepth = Integer.parseInt(cmd.getOptionValue("b", "16"));
            String onWriteProgram = cmd.getOptionValue("x");

            if (outputSampleRate <= 0) {
                throw new ParseException("sample-rate must be > 0");
            }
            if (outputChannels <= 0) {
                throw new ParseException("channels must be > 0");
            }
            if (outputBitDepth <= 0 || outputBitDepth % 8 != 0) {
                throw new ParseException("bitrate must be a positive multiple of 8 (for PCM bit depth)");
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
                            onWriteProgram);
                } else {
                    recorder = new StreamRecorder(
                            System.in,
                            outDir,
                            threshold,
                            silenceSeconds,
                            outputSampleRate,
                            outputChannels,
                            outputBitDepth,
                            onWriteProgram);
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
                        onWriteProgram);
            }
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
    public static void showHelp(Options options)
    {
        HelpFormatter formatter = new HelpFormatter();
        formatter.printHelp( "rms-cast-recorder", "RMSCastRecorder: record shoutcast/icecast streams or stdin audio", options, "" );
        System.err.println("For more information please visit https://openstatic.org/projects/rms-cast-recorder");
        System.exit(0);
    }
}
