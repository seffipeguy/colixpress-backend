<?php

namespace App\Core;

class Response
{
    /**
     * Convertir les dates en timezone locale si possible
     */
    private static function convertDates(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        // Essayer de récupérer le country_id de l'utilisateur connecté
        $countryId = 1; // Par défaut Cameroun
        
        try {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = new Auth();
                $user = $auth->user();
                if ($user && isset($user['country_id'])) {
                    $countryId = (int) $user['country_id'];
                }
            }
        } catch (\Exception $e) {
            // Ignorer les erreurs, utiliser le pays par défaut
        }

        return DateHelper::convertDatesInArray($data, $countryId);
    }

    public static function json(mixed $data, int $code = 200): void
    {
        // Convertir les dates avant de logger et renvoyer
        if (is_array($data)) {
            $data = self::convertDates($data);
        }

        // Log response
        $logContext = is_array($data) ? $data : ['data' => $data];
        if (isset($logContext['data']) && (is_array($logContext['data']) || is_object($logContext['data']))) {
             // Avoid logging huge data
             $logContext['data'] = '[DATA]'; 
        }
        Logger::info("RESPONSE: {$code}", $logContext);

        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function error(string $message = 'Error', int $code = 400, mixed $errors = null): void
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        self::json($response, $code);
    }

    public static function notFound(string $message = 'Resource not found'): void
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::error($message, 403);
    }

    public static function paginated(array $data, int $total, int $page, int $perPage): void
    {
        self::json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil($total / max($perPage, 1)),
            ],
        ]);
    }
}
