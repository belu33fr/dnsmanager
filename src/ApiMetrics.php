<?php

namespace GlpiPlugin\Dnsmanager;

/**
 * Compteur léger des appels HTTP faits vers les providers pendant la requête PHP
 * en cours (nombre d'appels et temps cumulé). Sert uniquement au diagnostic des
 * lenteurs de synchronisation : la synchro rapide affiche les totaux dans son résumé.
 */
class ApiMetrics
{
    private static int $calls = 0;
    private static float $seconds = 0.0;

    public static function record(float $seconds): void
    {
        self::$calls++;
        self::$seconds += $seconds;
    }

    /** @return array{calls:int, ms:int} */
    public static function snapshot(): array
    {
        return ['calls' => self::$calls, 'ms' => (int)round(self::$seconds * 1000)];
    }
}
