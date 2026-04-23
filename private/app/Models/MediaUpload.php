<?php

namespace App\Models;

use App\Core\Model;

class MediaUpload extends Model
{
    protected string $table = 'media_uploads';

    public const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
    ];
    public const MAX_SIZE = 10 * 1024 * 1024; // 10 MB

    public function findByReference(string $reference): ?array
    {
        $result = $this->findBy('reference', $reference);
        return $result ?: null;
    }

    public function generateReference(): string
    {
        do {
            $ref = 'MED-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } while ($this->findByReference($ref));
        return $ref;
    }

    /**
     * Résoudre et valider une liste de références appartenant à un utilisateur.
     * Retourne les lignes media ou lance une erreur si une référence est invalide/déjà liée.
     */
    public function resolveReferences(array $references, int $userId): array
    {
        $resolved = [];
        foreach ($references as $ref) {
            $media = $this->findByReference($ref);
            if (!$media) {
                return ['error' => "Référence média introuvable : {$ref}"];
            }
            if ((int) $media['uploaded_by'] !== $userId) {
                return ['error' => "Référence média non autorisée : {$ref}"];
            }
            if ($media['linked_to'] !== null) {
                return ['error' => "Référence média déjà utilisée : {$ref}"];
            }
            $resolved[] = $media;
        }
        return $resolved;
    }

    public function markLinked(int $id, string $linkedTo, int $linkedId): void
    {
        $this->update($id, ['linked_to' => $linkedTo, 'linked_id' => $linkedId]);
    }
}
