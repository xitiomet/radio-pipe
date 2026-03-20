package org.openstatic;

import javax.sound.sampled.AudioFormat;

public final class DcsDetector {
    static final double BITRATE = 134.4;
    private static final int CODEWORD_BITS = 23;
    private static final int CODEWORD_MASK = 0x7FFFFF;
    private static final int GOLAY_POLY = 0xC75;
    private static final int NO_CODE = -1;
    private static final int CONFIRM_SCORE_THRESHOLD = 4;
    private static final int CONFIRM_SCORE_THRESHOLD_OPEN = 6;
    private static final int MAX_CONFIDENCE_SCORE = 8;
    private static final long PLL_PHASE_MASK = 0xFFFFFFFFL;
    private static final boolean[] VALID_DCS_CODEBOOK = buildCodebook();
    private static final long PLL_PHASE_MIDPOINT = 0x80000000L;
    private static final long PLL_PHASE_WRAP = 0x1_0000_0000L;
    private static final double FILTER_CUTOFF_HZ = 180.0;
    private static final double DC_BLOCKER_R = 0.995;
    private static final double COMPARATOR_FLOOR_THRESHOLD = 250.0 / 32768.0;
    private static final double COMPARATOR_THRESHOLD_RATIO = 0.45;
    private static final double COMPARATOR_HYSTERESIS_RATIO = 0.20;

    private final Integer targetCode;
    private final long pllIncrement;
    private final long holdSamples;
    private final int confirmScoreThreshold;
    private final double envelopeAlpha;
    private final double b0;
    private final double b1;
    private final double b2;
    private final double a1;
    private final double a2;
    private double x1;
    private double x2;
    private double y1;
    private double y2;
    private double dcBlockPreviousInput;
    private double dcBlockPreviousOutput;
    private double comparatorEnvelope;
    private int lastComparatorBit;
    private int previousInputBit;
    private long pllPhase;
    private int shiftRegister;
    private long totalBits;
    private int confidenceScore;
    private int bitsSinceMatch;
    private int candidateCode;
    private int candidatePolarity;
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
        this.candidateCode = NO_CODE;
        this.candidatePolarity = -1;
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
        this.holdSamples = (this.targetCode == null)
                ? Math.max(1L, Math.round(sampleRate * 0.5))
                : Math.max(1L, Math.round(sampleRate));
        this.confirmScoreThreshold = (this.targetCode == null)
                ? CONFIRM_SCORE_THRESHOLD_OPEN
                : CONFIRM_SCORE_THRESHOLD;
        this.envelopeAlpha = Math.max(0.0005, Math.min(0.05, 1.0 / (sampleRate * 0.05)));
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

    public int getConfidenceScore() {
        return this.confidenceScore;
    }

    public Integer getCandidateCode() {
        return (this.candidateCode == NO_CODE) ? null : Integer.valueOf(this.candidateCode);
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
        double dcBlocked = sample - this.dcBlockPreviousInput + (DC_BLOCKER_R * this.dcBlockPreviousOutput);
        this.dcBlockPreviousInput = sample;
        this.dcBlockPreviousOutput = dcBlocked;

        double filtered = (this.b0 * dcBlocked)
                + (this.b1 * this.x1)
                + (this.b2 * this.x2)
                - (this.a1 * this.y1)
                - (this.a2 * this.y2);

        this.x2 = this.x1;
        this.x1 = dcBlocked;
        this.y2 = this.y1;
        this.y1 = filtered;

        int bit = comparator(filtered);

        if (bit != this.previousInputBit) {
            long phaseError = (this.pllPhase < PLL_PHASE_MIDPOINT)
                    ? this.pllPhase
                    : (this.pllPhase - PLL_PHASE_WRAP);
            this.pllPhase = (this.pllPhase - (phaseError >> 5)) & PLL_PHASE_MASK;
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
        double magnitude = Math.abs(sample);
        this.comparatorEnvelope += this.envelopeAlpha * (magnitude - this.comparatorEnvelope);

        double baseThreshold = Math.max(COMPARATOR_FLOOR_THRESHOLD,
                this.comparatorEnvelope * COMPARATOR_THRESHOLD_RATIO);
        double highThreshold = baseThreshold * (1.0 + COMPARATOR_HYSTERESIS_RATIO);
        double lowThreshold = baseThreshold * (1.0 - COMPARATOR_HYSTERESIS_RATIO);

        if (sample > highThreshold) {
            this.lastComparatorBit = 1;
        } else if (sample < -highThreshold) {
            this.lastComparatorBit = 0;
        } else if (this.lastComparatorBit == 1 && sample < -lowThreshold) {
            this.lastComparatorBit = 0;
        } else if (this.lastComparatorBit == 0 && sample > lowThreshold) {
            this.lastComparatorBit = 1;
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
            if (this.targetCode != null && foundCode != this.targetCode.intValue()) {
                foundPolarity = -1;
            } else {
                observeCandidate(foundCode, foundPolarity);
                this.bitsSinceMatch = 0;
                if (this.confidenceScore >= this.confirmScoreThreshold) {
                    this.lastConfirmedSample = this.sampleCursor;
                    this.lastDetectedCode = this.candidateCode;
                    this.lastPolarity = this.candidatePolarity;
                }
            }
        }

        if (foundPolarity < 0 && this.bitsSinceMatch >= CODEWORD_BITS) {
            decayCandidate();
            this.bitsSinceMatch = -1;
        }
    }

    private void observeCandidate(int foundCode, int foundPolarity) {
        if (this.candidateCode == foundCode && this.candidatePolarity == foundPolarity) {
            this.confidenceScore = Math.min(MAX_CONFIDENCE_SCORE, this.confidenceScore + 1);
            return;
        }

        if (this.confidenceScore > 1) {
            this.confidenceScore--;
            return;
        }

        this.candidateCode = foundCode;
        this.candidatePolarity = foundPolarity;
        this.confidenceScore = 1;
    }

    private void decayCandidate() {
        if (this.confidenceScore > 0) {
            this.confidenceScore--;
        }
        if (this.confidenceScore <= 0) {
            this.confidenceScore = 0;
            this.candidateCode = NO_CODE;
            this.candidatePolarity = -1;
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

        int code = data12 & 0x1FF;
        if (!VALID_DCS_CODEBOOK[code]) {
            return -1;
        }
        return code;
    }

    private boolean isGateOpen() {
        Integer detectedCode = getDetectedCode();
        if (detectedCode == null) {
            return false;
        }
        return this.targetCode == null || detectedCode.intValue() == this.targetCode.intValue();
    }

    private static boolean[] buildCodebook() {
        int[] codes = {
            023, 025, 026, 031, 032, 036, 043, 047, 051, 053, 054, 065, 071, 072, 073, 074,
            0114, 0115, 0116, 0121, 0122, 0123, 0124, 0125, 0131, 0132, 0134, 0141, 0143, 0145, 0152, 0155,
            0156, 0162, 0165, 0172, 0174,
            0205, 0212, 0214, 0223, 0225, 0226, 0231, 0243, 0244, 0245, 0246, 0251, 0252, 0255, 0261, 0263,
            0265, 0266, 0271, 0274,
            0306, 0311, 0315, 0325, 0331, 0332, 0343, 0346, 0351, 0356, 0364, 0365, 0371,
            0411, 0412, 0413, 0423, 0431, 0432, 0445, 0446, 0452, 0454, 0455, 0462, 0464, 0465, 0466,
            0503, 0506, 0516, 0523, 0525, 0526, 0532, 0546, 0565,
            0606, 0612, 0624, 0627, 0631, 0632, 0645, 0652, 0654, 0662, 0664,
            0703, 0712, 0723, 0731, 0732, 0734, 0743, 0754
        };
        boolean[] table = new boolean[512];
        for (int code : codes) {
            table[code] = true;
        }
        return table;
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