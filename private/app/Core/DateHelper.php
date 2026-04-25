<?php

namespace App\Core;

use DateTime;
use DateTimeZone;

class DateHelper
{
    /**
     * Timezones par pays
     */
    private static array $countryTimezones = [
        1 => 'Africa/Douala',      // Cameroun (UTC+1)
        2 => 'Africa/Libreville',  // Gabon (UTC+1)
        3 => 'Africa/Brazzaville', // Congo (UTC+1)
        4 => 'Africa/Abidjan',     // Côte d'Ivoire (UTC+0)
        5 => 'Africa/Dakar',       // Sénégal (UTC+0)
    ];

    /**
     * Convertir une date UTC en timezone locale du pays
     */
    public static function toLocalTime(?string $utcDate, int $countryId = 1): ?string
    {
        if (!$utcDate) {
            return null;
        }

        try {
            $timezone = self::$countryTimezones[$countryId] ?? 'Africa/Douala';
            
            $date = new DateTime($utcDate, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($timezone));
            
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $utcDate; // Retourner la date originale en cas d'erreur
        }
    }

    /**
     * Convertir toutes les dates d'un tableau récursivement
     */
    public static function convertDatesInArray(array $data, int $countryId = 1): array
    {
        $dateFields = [
            'created_at',
            'updated_at',
            'initiated_at',
            'completed_at',
            'failed_at',
            'refunded_at',
            'webhook_received_at',
            'expires_at',
            'deleted_at',
            'verified_at',
            'last_login',
            'pickup_time',
            'delivery_time',
            'estimated_pickup',
            'estimated_delivery',
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Récursif pour les tableaux imbriqués
                $data[$key] = self::convertDatesInArray($value, $countryId);
            } elseif (in_array($key, $dateFields) && is_string($value)) {
                // Convertir les champs de date
                $data[$key] = self::toLocalTime($value, $countryId);
            }
        }

        return $data;
    }

    /**
     * Obtenir le timezone d'un pays
     */
    public static function getCountryTimezone(int $countryId): string
    {
        return self::$countryTimezones[$countryId] ?? 'Africa/Douala';
    }

    /**
     * Obtenir l'offset UTC d'un pays (ex: "+01:00")
     */
    public static function getCountryOffset(int $countryId): string
    {
        $timezone = self::getCountryTimezone($countryId);
        $tz = new DateTimeZone($timezone);
        $offset = $tz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        
        $hours = floor($offset / 3600);
        $minutes = abs(($offset % 3600) / 60);
        
        return sprintf('%+03d:%02d', $hours, $minutes);
    }
}
