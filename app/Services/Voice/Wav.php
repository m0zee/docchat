<?php

namespace App\Services\Voice;

use App\Exceptions\SpeechException;

/**
 * Joins WAV files that share one PCM format into one file.
 */
final class Wav
{
    /**
     * @param  list<string>  $files
     */
    public static function join(array $files): string
    {
        $format = null;
        $data = '';

        foreach ($files as $file) {
            [$fmt, $pcm] = self::parse($file);
            $format ??= $fmt;

            if ($fmt !== $format) {
                throw new SpeechException('The audio pieces have different formats.');
            }

            $data .= $pcm;
        }

        if ($format === null) {
            throw new SpeechException('No audio.');
        }

        $fmtChunk = 'fmt '.pack('V', strlen($format)).$format;
        $dataChunk = 'data'.pack('V', strlen($data)).$data;

        return 'RIFF'.pack('V', 4 + strlen($fmtChunk) + strlen($dataChunk)).'WAVE'.$fmtChunk.$dataChunk;
    }

    /**
     * @return array{string, string} the fmt chunk body and the PCM data
     */
    public static function parse(string $file): array
    {
        if (strlen($file) < 12 || substr($file, 0, 4) !== 'RIFF' || substr($file, 8, 4) !== 'WAVE') {
            throw new SpeechException('Not a WAV file.');
        }

        $offset = 12;
        $format = null;
        $length = strlen($file);

        while ($offset + 8 <= $length) {
            $id = substr($file, $offset, 4);
            $size = unpack('V', substr($file, $offset + 4, 4))[1] ?? 0;
            $body = $offset + 8;

            if ($id === 'fmt ') {
                $format = substr($file, $body, (int) $size);
            } elseif ($id === 'data') {
                if ($format === null) {
                    throw new SpeechException('WAV data before its format.');
                }

                // Streamed WAVs can carry a placeholder size: take what is there.
                $size = ($size === 0 || $size === 0xFFFFFFFF || $body + $size > $length) ? $length - $body : $size;

                return [$format, substr($file, $body, (int) $size)];
            }

            $offset = $body + (int) $size + ((int) $size % 2);
        }

        throw new SpeechException('WAV file without audio data.');
    }

    /**
     * Mono 16-bit PCM samples (-1..1) as a WAV file.
     *
     * @param  list<float>  $samples
     */
    public static function fromSamples(array $samples, int $rate = 8000): string
    {
        $data = '';

        foreach ($samples as $sample) {
            $data .= pack('v', (int) round(max(-1.0, min(1.0, $sample)) * 32767) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    /**
     * A soft, short hold sound for the phone line while an answer is written:
     * a few quiet sine chords that fade in and out. Made here, so there is no
     * music file to license.
     */
    public static function holdTone(float $seconds = 3.0, int $rate = 8000): string
    {
        // A gentle I-vi-IV-V in C, one chord at a time.
        $chords = [[261.63, 329.63, 392.0], [220.0, 261.63, 329.63], [174.61, 220.0, 261.63], [196.0, 246.94, 293.66]];
        $total = (int) round($seconds * $rate);
        $perChord = intdiv($total, count($chords));
        $samples = [];

        for ($i = 0; $i < $total; $i++) {
            $chord = $chords[min(count($chords) - 1, intdiv($i, max(1, $perChord)))];
            $t = $i / $rate;
            $local = ($i % max(1, $perChord)) / max(1, $perChord);
            // Each chord swells and fades, so the joins are smooth.
            $envelope = sin(M_PI * $local) ** 2;
            $value = 0.0;

            foreach ($chord as $frequency) {
                $value += sin(2 * M_PI * $frequency * $t);
            }

            $samples[] = 0.08 * $envelope * $value / count($chord);
        }

        return self::fromSamples($samples, $rate);
    }
}
