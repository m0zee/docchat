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
}
