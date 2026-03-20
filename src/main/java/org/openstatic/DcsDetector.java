package org.openstatic;

import javax.sound.sampled.AudioFormat;

public final class DcsDetector {
    static final double BITRATE = 134.4;
    private static final int CODEWORD_BITS = 23;
    private static final int CODEWORD_MASK = 0x7FFFFF;
    private static final int GOLAY_POLY = 0xC75;
    private static final int NO_CODE = -1;
    private static final long PLL_PHASE_MASK = 0xFFFFFFFFL;
    private static final long PLL_PHASE_MIDPOINT = 0x80000000L;
    private static final long PLL_PHASE_WRAP = 0x1_0000_0000L;
    private static final double FILTER_CUTOFF_HZ = 300.0;
    private static final double COMPARATOR_THRESHOLD = 500.0 / 32768.0;

    private final Integer targetCode;
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
    private int matchedCode;
    private int matchedPolarity;
    private int lastDetectedCode;
    private int lastPolarity;
    private long sampleCursor;
    private long lastConfirmedSample;

    public DcsDetector(AudioFormat format) {
        this(format, null);
    }

    public DcsDetector(AudioFormat format, int targetCode) {
        this(format, Integer.valueOf(targetCode));
    }

    private DcsDetector(AudioFormat format, Integer targetCode) {
        if (format.getSampleRate() <= 0) {
            throw new IllegalArgumentException("Invalid sample rate for DCS detector");
        }

        this.targetCode = (targetCode == null) ? null : Integer.valueOf(targetCode.intValue() & 0x1FF);
        this.bitsSinceMatch = -1;
        this.matchedCode = NO_CODE;
        this.matchedPolarity = -1;
        this.lastDetectedCode = NO_CODE;
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
                Math.round((BITRATE / sampleRate) * PLL_PHASE_WRAP));
        this.holdSamples = Math.max(1L, Math.round(sampleRate));
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
            mixedSample /= channels;
            processSample(mixedSample);
        }

        return isGateOpen();
    }

    public Integer getDetectedCode() {
        return isDetected() ? Integer.valueOf(this.lastDetectedCode) : null;
    }

    public boolean isDetected() {
        if (this.lastConfirmedSample < 0 || this.lastDetectedCode < 0) {
            return false;
        }
        return (this.sampleCursor - this.lastConfirmedSample) <= this.holdSamples;
    }

    public String getPolarityLabel() {
        if (this.lastPolarity == 0) {
            return "normal";
        }
        if (this.lastPolarity == 1) {
            return "inverted";
        }
        return "unknown";
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
        this.shiftRegister = ((this.shiftRegister >>> 1) | (bit << 22)) & CODEWORD_MASK;
        this.totalBits++;
        checkForMatch();
    }

    private void checkForMatch() {
        if (this.totalBits < CODEWORD_BITS) {
            return;
        }

        if (this.bitsSinceMatch >= 0) {
            this.bitsSinceMatch++;
            if (this.bitsSinceMatch < CODEWORD_BITS) {
                return;
            }
        }

        int foundPolarity = -1;
        int foundCode = extractCode(this.shiftRegister, false);
        if (foundCode >= 0) {
            foundPolarity = 0;
        } else {
            foundCode = extractCode(this.shiftRegister, true);
            if (foundCode >= 0) {
                foundPolarity = 1;
            }
        }

        if (foundPolarity >= 0) {
            if (this.bitsSinceMatch == CODEWORD_BITS
                    && foundPolarity == this.matchedPolarity
                    && foundCode == this.matchedCode) {
                this.matchCount++;
            } else {
                this.matchCount = 1;
                this.matchedCode = foundCode;
                this.matchedPolarity = foundPolarity;
            }

            this.bitsSinceMatch = 0;
            if (this.matchCount >= 3) {
                this.lastConfirmedSample = this.sampleCursor;
                this.lastDetectedCode = foundCode;
                this.lastPolarity = foundPolarity;
            }
        } else if (this.bitsSinceMatch >= CODEWORD_BITS) {
            this.matchCount = 0;
            this.bitsSinceMatch = -1;
            this.matchedCode = NO_CODE;
            this.matchedPolarity = -1;
        }
    }

    private int extractCode(int codeword, boolean invert) {
        int candidate = invert ? (codeword ^ CODEWORD_MASK) : codeword;
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
        Integer detectedCode = getDetectedCode();
        if (detectedCode == null) {
            return false;
        }
        return this.targetCode == null || detectedCode.intValue() == this.targetCode.intValue();
    }

    private static boolean isValidGolayCodeword(int codeword) {
        int remainder = codeword & CODEWORD_MASK;
        for (int shift = 11; shift >= 0; shift--) {
            if ((remainder & (1 << (shift + 11))) != 0) {
                remainder ^= (GOLAY_POLY << shift);
            }
        }
        return (remainder & CODEWORD_MASK) == 0;
    }
}