package org.openstatic;

import javax.sound.sampled.AudioFormat;

public final class CtcssDetector {
    private static final double[] STANDARD_TONES_HZ = new double[] {
            67.0, 69.3, 71.9, 74.4, 77.0, 79.7, 82.5, 85.4, 88.5, 91.5,
            94.8, 97.4, 100.0, 103.5, 107.2, 110.9, 114.8, 118.8, 123.0, 127.3,
            131.8, 136.5, 141.3, 146.2, 151.4, 156.7, 159.8, 162.2, 165.5, 167.9,
            171.3, 173.8, 177.3, 179.9, 183.5, 186.2, 189.9, 192.8, 196.6, 199.5,
            203.5, 206.5, 210.7, 218.1, 225.7, 229.1, 233.6, 241.8, 250.3, 254.1
    };
    private static final double MIN_TONE_HZ = 50.0;
    private static final double MAX_TONE_HZ = 300.0;
    private static final double ANALYSIS_WINDOW_SECONDS = 0.2;
    private static final int OPEN_MATCH_WINDOWS = 2;
    private static final int CLOSE_MISS_WINDOWS = 3;
    private static final double MIN_TARGET_AMPLITUDE = 0.0025;
    private static final double DOMINANCE_RATIO = 1.6;
    private static final double NEIGHBOR_OFFSET_HZ = 4.0;

    private final double sampleRate;
    private final Double targetHz;
    private final double lowerNeighborHz;
    private final double upperNeighborHz;
    private final int windowSamples;
    private final double[] sampleWindow;
    private int sampleIndex;
    private int matchWindows;
    private int missWindows;
    private boolean detected;
    private double candidateToneHz;
    private double detectedToneHz;

    public CtcssDetector(AudioFormat format) {
        this(format, null);
    }

    public CtcssDetector(AudioFormat format, double targetHz) {
        this(format, Double.valueOf(targetHz));
    }

    private CtcssDetector(AudioFormat format, Double targetHz) {
        if (format.getSampleRate() <= 0) {
            throw new IllegalArgumentException("Invalid sample rate for CTCSS detector");
        }

        this.sampleRate = format.getSampleRate();
        this.targetHz = targetHz;
        this.lowerNeighborHz = (targetHz == null) ? 0.0 : chooseLowerNeighbor(targetHz.doubleValue());
        this.upperNeighborHz = (targetHz == null) ? 0.0 : chooseUpperNeighbor(targetHz.doubleValue());
        this.windowSamples = Math.max(200, (int) Math.round(this.sampleRate * ANALYSIS_WINDOW_SECONDS));
        this.sampleWindow = new double[this.windowSamples];
        this.sampleIndex = 0;
        this.matchWindows = 0;
        this.missWindows = 0;
        this.detected = false;
        this.candidateToneHz = Double.NaN;
        this.detectedToneHz = Double.NaN;
    }

    public boolean consume(byte[] data, int len, AudioFormat format) {
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

    public Double getDetectedToneHz() {
        return this.detected ? Double.valueOf(this.detectedToneHz) : null;
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

    private void analyzeWindow() {
        double mean = 0.0;
        for (int i = 0; i < this.windowSamples; i++) {
            mean += this.sampleWindow[i];
        }
        mean /= this.windowSamples;

        Double matchedTone = (this.targetHz != null)
                ? analyzeTargetTone(mean)
                : analyzeAnyTone(mean);

        if (matchedTone == null) {
            this.missWindows++;
            this.matchWindows = 0;
            this.candidateToneHz = Double.NaN;
            if (this.detected && this.missWindows >= CLOSE_MISS_WINDOWS) {
                this.detected = false;
                this.detectedToneHz = Double.NaN;
            }
            return;
        }

        if (Double.isNaN(this.candidateToneHz) || !sameTone(this.candidateToneHz, matchedTone.doubleValue())) {
            this.candidateToneHz = matchedTone.doubleValue();
            this.matchWindows = 1;
        } else {
            this.matchWindows++;
        }
        this.missWindows = 0;

        if (!this.detected && this.matchWindows >= OPEN_MATCH_WINDOWS) {
            this.detected = true;
            this.detectedToneHz = this.candidateToneHz;
        } else if (this.detected) {
            if (sameTone(this.detectedToneHz, matchedTone.doubleValue()) || this.matchWindows >= OPEN_MATCH_WINDOWS) {
                this.detectedToneHz = matchedTone.doubleValue();
            }
        }
    }

    private Double analyzeTargetTone(double mean) {
        double targetAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.targetHz.doubleValue(), this.sampleRate, mean);
        double lowerAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.lowerNeighborHz, this.sampleRate, mean);
        double upperAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, this.upperNeighborHz, this.sampleRate, mean);

        boolean match = targetAmp >= MIN_TARGET_AMPLITUDE
                && targetAmp >= (lowerAmp * DOMINANCE_RATIO)
                && targetAmp >= (upperAmp * DOMINANCE_RATIO);
        return match ? this.targetHz : null;
    }

    private Double analyzeAnyTone(double mean) {
        double strongestToneHz = Double.NaN;
        double strongestAmp = 0.0;
        double secondStrongestAmp = 0.0;

        for (double candidateToneHz : STANDARD_TONES_HZ) {
            double candidateAmp = goertzelAmplitude(this.sampleWindow, this.windowSamples, candidateToneHz, this.sampleRate, mean);
            if (candidateAmp > strongestAmp) {
                secondStrongestAmp = strongestAmp;
                strongestAmp = candidateAmp;
                strongestToneHz = candidateToneHz;
            } else if (candidateAmp > secondStrongestAmp) {
                secondStrongestAmp = candidateAmp;
            }
        }

        if (Double.isNaN(strongestToneHz) || strongestAmp < MIN_TARGET_AMPLITUDE) {
            return null;
        }

        double lowerAmp = goertzelAmplitude(
                this.sampleWindow,
                this.windowSamples,
                chooseLowerNeighbor(strongestToneHz),
                this.sampleRate,
                mean);
        double upperAmp = goertzelAmplitude(
                this.sampleWindow,
                this.windowSamples,
                chooseUpperNeighbor(strongestToneHz),
                this.sampleRate,
                mean);

        boolean match = strongestAmp >= (secondStrongestAmp * DOMINANCE_RATIO)
                && strongestAmp >= (lowerAmp * DOMINANCE_RATIO)
                && strongestAmp >= (upperAmp * DOMINANCE_RATIO);
        return match ? Double.valueOf(strongestToneHz) : null;
    }

    private static boolean sameTone(double first, double second) {
        return Math.abs(first - second) < 0.05;
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