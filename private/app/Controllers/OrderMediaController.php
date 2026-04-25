<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Order;
use App\Models\OrderMedia;

class OrderMediaController extends Controller
{
    /**
     * POST /api/orders/{reference}/media
     * Upload un média lié à une commande (multipart/form-data, champ "file")
     */
    public function upload(Request $request): void
    {
        $order = $this->resolveOrder($request->param('reference'));

        if ($order['status'] !== 'pending') {
            Response::error('Médias modifiables uniquement si la commande est en attente', 422);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Fichier manquant ou erreur d\'upload', 422);
        }

        $file     = $_FILES['file'];
        $mimeType = mime_content_type($file['tmp_name']);

        if (!array_key_exists($mimeType, OrderMedia::ALLOWED_MIME)) {
            Response::error('Type de fichier non autorisé. Formats acceptés : images (jpg, png, gif, webp), vidéos (mp4, mov, avi), audio (mp3, m4a, ogg, wav), documents (pdf, doc, txt)', 422);
        }

        if ($file['size'] > OrderMedia::MAX_SIZE) {
            Response::error('Fichier trop volumineux (max 10 MB)', 422);
        }

        $mediaModel = new OrderMedia();
        if ($mediaModel->countByOrder((int) $order['id']) >= OrderMedia::MAX_PER_ORDER) {
            Response::error('Maximum ' . OrderMedia::MAX_PER_ORDER . ' médias par commande', 422);
        }

        // Construire le chemin de stockage
        $ref       = $order['reference'];
        $ext       = OrderMedia::ALLOWED_MIME[$mimeType];
        $fileName  = uniqid('media_', true) . '.' . $ext;
        $dir       = UPLOAD_DIR . '/orders/' . $ref;
        $filePath  = $dir . '/' . $fileName;
        $fileUrl   = UPLOAD_URL . '/orders/' . $ref . '/' . $fileName;
        $fileType  = str_starts_with($mimeType, 'video/') ? 'video'
                    : (str_starts_with($mimeType, 'audio/') ? 'audio'
                    : (str_starts_with($mimeType, 'image/') ? 'image' : 'document'));

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            Response::error('Échec de l\'enregistrement du fichier', 500);
        }

        $id = $mediaModel->create([
            'order_id'    => (int) $order['id'],
            'file_name'   => $fileName,
            'file_path'   => $filePath,
            'file_url'    => $fileUrl,
            'file_type'   => $fileType,
            'mime_type'   => $mimeType,
            'file_size'   => $file['size'],
            'uploaded_by' => $this->userId(),
        ]);

        Response::json([
            'success' => true,
            'message' => 'Média uploadé avec succès',
            'data'    => [
                'id'        => $id,
                'file_url'  => $fileUrl,
                'file_type' => $fileType,
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
            ],
        ], 201);
    }

    /**
     * GET /api/orders/{reference}/media
     * Liste les médias d'une commande
     */
    public function index(Request $request): void
    {
        $order      = $this->resolveOrder($request->param('reference'));
        $mediaModel = new OrderMedia();
        $media      = $mediaModel->getByOrder((int) $order['id']);

        Response::success($media);
    }

    /**
     * DELETE /api/orders/{reference}/media/{id}
     * Supprime un média (client propriétaire ou admin uniquement)
     */
    public function destroy(Request $request): void
    {
        $order      = $this->resolveOrder($request->param('reference'));
        $mediaModel = new OrderMedia();
        $media      = $mediaModel->findForOrder((int) $request->param('id'), (int) $order['id']);

        if (!$media) {
            Response::notFound('Média non trouvé');
        }

        // Seul le propriétaire de la commande ou un admin peut supprimer
        if (!Auth::isAdmin() && (int) $order['client_id'] !== $this->userId()) {
            Response::forbidden();
        }

        if ($order['status'] !== 'pending') {
            Response::error('Suppression impossible une fois la commande acceptée', 422);
        }

        // Supprimer le fichier physique
        if (file_exists($media['file_path'])) {
            unlink($media['file_path']);
        }

        $mediaModel->delete((int) $media['id']);

        Response::success(null, 'Média supprimé');
    }

    /**
     * Résoudre la commande par référence avec contrôle d'accès
     */
    private function resolveOrder(string $reference): array
    {
        $orderModel = new Order();
        $order      = $orderModel->findByReference($reference);

        if (!$order) {
            Response::notFound('Commande non trouvée');
        }

        // Seul le client propriétaire, le livreur assigné ou un admin
        if (!Auth::isAdmin()
            && (int) $order['client_id'] !== $this->userId()
            && (int) $order['livreur_id'] !== $this->userId()
        ) {
            Response::forbidden();
        }

        return $order;
    }
}
