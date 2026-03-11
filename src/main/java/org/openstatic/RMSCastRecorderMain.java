package org.openstatic;

import org.apache.commons.cli.*;

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
                .desc("Shoutcast/Icecast stream URL to record").required().build());
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
            URL url = new URL(cmd.getOptionValue("u"));
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

            StreamRecorder recorder = new StreamRecorder(
                    url,
                    outDir,
                    threshold,
                    silenceSeconds,
                    outputSampleRate,
                    outputChannels,
                    outputBitDepth,
                    onWriteProgram);
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
        formatter.printHelp( "rms-cast-recorder", "RMSCastRecorder: A tool for recording shoutcast or icecast streams", options, "" );
        System.exit(0);
    }
}
