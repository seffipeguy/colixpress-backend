<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\MediaUpload;

class MediaController extends Controller
{
    /**
     * POST /api/media/upload
     * Upload un fichier, retourne une référence à utiliser dans la création de commande/template.
     * Content-Type: multipart/form-data, champ "file"
     */
    public function upload(Request $request): void
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Fichier manquant ou erreur d\'upload', 422);
        }

        $file     = $_FILES['file'];
        $mimeType = mime_content_type($file['tmp_name']);

        $allowedMime = array_merge(MediaUpload::ALLOWED_MIME, [
            // Documents PDF
            'application/pdf'      => 'pdf',
            // Documents Word
            'application/msword'   => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            // Documents Excel
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            // Documents PowerPoint
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            // Texte
            'text/plain' => 'txt',
            'text/csv'   => 'csv',
            // Archives
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
        ]);

        if (!array_key_exists($mimeType, $allowedMime)) {
            Response::error('Type de fichier non autorisé. Formats acceptés : images (jpg, png, gif, webp), vidéos (mp4, mov, avi), audio (mp3, m4a, ogg, wav, aac), documents (pdf, doc, xls, txt)', 422);
        }

        if ($file['size'] > MediaUpload::MAX_SIZE) {
            Response::error('Fichier trop volumineux (max 10 MB)', 422);
        }

        $mediaModel = new MediaUpload();
        $reference  = $mediaModel->generateReference();
        $ext        = $allowedMime[$mimeType];
        $fileName   = $reference . '.' . $ext;
        $dir        = UPLOAD_DIR . '/media';
        $filePath   = $dir . '/' . $fileName;
        $fileUrl    = UPLOAD_URL . '/media/' . $fileName;
        $fileType   = str_starts_with($mimeType, 'video/') ? 'video'
                    : (str_starts_with($mimeType, 'audio/') ? 'audio'
                    : (str_starts_with($mimeType, 'image/') ? 'image' : 'document'));

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            Response::error('Échec de l\'enregistrement du fichier', 500);
        }

        $mediaId = $mediaModel->create([
            'reference'   => $reference,
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'file_url'    => $fileUrl,
            'file_type'   => $fileType,
            'mime_type'   => $mimeType,
            'file_size'   => $file['size'],
            'uploaded_by' => $this->userId(),
        ]);

        Response::success([
            'id'        => $mediaId,
            'reference' => $reference,
            'file_url'  => $fileUrl,
            'file_type' => $fileType,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
        ], 'Fichier uploadé avec succès', 201);
    }

    /**
     * DELETE /api/media/{reference}
     * Supprime un média non encore attaché à une commande ou template.
     */
    public function destroy(Request $request): void
    {
        $reference  = $request->param('reference');
        $mediaModel = new MediaUpload();
        $media      = $mediaModel->findByReference($reference);

        if (!$media) {
            Response::notFound('Média introuvable');
        }

        if ((int) $media['uploaded_by'] !== $this->userId()) {
            Response::forbidden();
        }

        if ($media['linked_to'] !== null) {
            Response::error('Ce média est déjà attaché à une ' . $media['linked_to'] . ' et ne peut pas être supprimé', 422);
        }

        if (file_exists($media['file_path'])) {
            unlink($media['file_path']);
        }

        $mediaModel->delete((int) $media['id']);

        Response::success(null, 'Média supprimé');
    }
}
