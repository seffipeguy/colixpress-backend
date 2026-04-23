<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\MediaUpload;
use App\Models\OrderTemplate;
use App\Models\Order;
use App\Models\OrderMedia;

class OrderTemplateController extends Controller
{
    /**
     * Create a new order template
     */
    public function store(Request $request): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $request->validate(['name', 'default_values']);
        
        $templateModel = new OrderTemplate();

        $customSlug = $request->input('slug');
        if ($customSlug !== null) {
            $customSlug = strtolower(trim($customSlug));
            if (!preg_match('/^[a-z0-9-]{3,50}$/', $customSlug)) {
                Response::error('Le slug ne peut contenir que des lettres minuscules, chiffres et tirets (3-50 caractères)', 422);
            }
            if ($templateModel->findBySlug($customSlug)) {
                Response::error('Ce slug est déjà utilisé', 409);
            }
            $slug = $customSlug;
        } else {
            $slug = $templateModel->generateSlug();
        }

        // Résoudre les références médias et les intégrer dans default_values
        $mediaRefs     = $request->input('media_references', []);
        $defaultValues = $request->input('default_values', []);
        if (is_string($defaultValues)) {
            $defaultValues = json_decode($defaultValues, true) ?? [];
        }

        // Valeur par défaut pour le mode de paiement
        if (empty($defaultValues['payment_method'])) {
            $defaultValues['payment_method'] = 'cash';
        }

        if (!empty($mediaRefs) && is_array($mediaRefs)) {
            $mediaUploadModel = new MediaUpload();
            $resolved = $mediaUploadModel->resolveReferences($mediaRefs, $userId);
            if (isset($resolved['error'])) {
                Response::error($resolved['error'], 422);
            }
            $packageMedia = [];
            foreach ($resolved as $m) {
                $packageMedia[] = [
                    'reference' => $m['reference'],
                    'file_url'  => $m['file_url'],
                    'file_path' => $m['file_path'],
                    'file_type' => $m['file_type'],
                    'mime_type' => $m['mime_type'],
                    'file_size' => $m['file_size'],
                ];
            }
            $defaultValues['package_media'] = $packageMedia;
        }

        $data = [
            'user_id'         => $userId,
            'name'            => $request->input('name'),
            'slug'            => $slug,
            'type'            => $request->input('type', 'custom'),
            'default_values'  => $defaultValues,
            'required_fields' => $request->input('required_fields', []),
            'is_active'       => 1
        ];

        $id = $templateModel->create($data);

        // Marquer les médias comme liés au template
        if (!empty($mediaRefs) && is_array($mediaRefs)) {
            foreach ($resolved as $m) {
                $mediaUploadModel->markLinked((int) $m['id'], 'template', $id);
            }
        }

        $data['id'] = $id;

        Response::json([
            'success' => true,
            'message' => 'Template created successfully',
            'data'    => $data,
            'link'    => "https://colixpress.com/t/" . $slug
        ], 201);
    }

    /**
     * PUT /api/templates/{slug}
     * Modifier un template (propriétaire uniquement)
     */
    public function update(Request $request): void
    {
        $slug          = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template      = $templateModel->findBySlug($slug);

        if (!$template) {
            Response::notFound('Template non trouvé');
        }

        if ((int) $template['user_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $updatable = [];

        if ($request->input('name') !== null) {
            $updatable['name'] = $request->input('name');
        }

        if ($request->input('type') !== null) {
            $updatable['type'] = $request->input('type');
        }

        if ($request->input('is_active') !== null) {
            $updatable['is_active'] = (int) $request->input('is_active');
        }

        if ($request->input('required_fields') !== null) {
            $updatable['required_fields'] = json_encode($request->input('required_fields'));
        }

        // Modifier le slug
        if ($request->input('slug') !== null) {
            $newSlug = strtolower(trim($request->input('slug')));
            if (!preg_match('/^[a-z0-9-]{3,50}$/', $newSlug)) {
                Response::error('Slug invalide (lettres minuscules, chiffres, tirets, 3-50 caractères)', 422);
            }
            $existing = $templateModel->findBySlug($newSlug);
            if ($existing && (int) $existing['id'] !== (int) $template['id']) {
                Response::error('Ce slug est déjà utilisé', 409);
            }
            $updatable['slug'] = $newSlug;
        }

        // Modifier default_values (merge avec l'existant)
        if ($request->input('default_values') !== null) {
            $current       = json_decode($template['default_values'], true) ?? [];
            $incoming      = $request->input('default_values');
            if (is_string($incoming)) {
                $incoming = json_decode($incoming, true) ?? [];
            }
            $updatable['default_values'] = json_encode(array_merge($current, $incoming), JSON_UNESCAPED_UNICODE);
        }

        // Attacher de nouveaux médias via media_references
        $mediaRefs = $request->input('media_references', []);
        if (!empty($mediaRefs) && is_array($mediaRefs)) {
            $mediaUploadModel = new MediaUpload();
            $current       = isset($updatable['default_values'])
                ? (json_decode($updatable['default_values'], true) ?? [])
                : (json_decode($template['default_values'], true) ?? []);
            $existingMedia = $current['package_media'] ?? [];

            // Références déjà présentes dans ce template (pour ne pas les bloquer)
            $alreadyLinkedRefs = array_column($existingMedia, 'reference');

            foreach ($mediaRefs as $ref) {
                // Si déjà dans ce template, on skip sans erreur
                if (in_array($ref, $alreadyLinkedRefs)) {
                    continue;
                }
                $media = $mediaUploadModel->findByReference($ref);
                if (!$media) {
                    Response::error("Référence média introuvable : {$ref}", 422);
                }
                if ((int) $media['uploaded_by'] !== $this->userId()) {
                    Response::error("Référence média non autorisée : {$ref}", 422);
                }
                if ($media['linked_to'] !== null) {
                    Response::error("Référence média déjà utilisée ailleurs : {$ref}", 422);
                }
                $existingMedia[] = [
                    'reference' => $media['reference'],
                    'file_url'  => $media['file_url'],
                    'file_path' => $media['file_path'],
                    'file_type' => $media['file_type'],
                    'mime_type' => $media['mime_type'],
                    'file_size' => $media['file_size'],
                ];
                $mediaUploadModel->markLinked((int) $media['id'], 'template', (int) $template['id']);
            }
            $current['package_media']    = $existingMedia;
            $updatable['default_values'] = json_encode($current, JSON_UNESCAPED_UNICODE);
        }

        if (empty($updatable)) {
            Response::error('Aucun champ à mettre à jour', 422);
        }

        $templateModel->update((int) $template['id'], $updatable);
        $updated = $templateModel->findBySlug($updatable['slug'] ?? $slug);
        $updated = OrderTemplate::sanitizeForResponse($updated);

        Response::success($updated, 'Template mis à jour');
    }

    /**
     * DELETE /api/templates/{slug}
     * Supprimer un template (propriétaire uniquement)
     */
    public function destroy(Request $request): void
    {
        $slug          = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template      = $templateModel->findBySlug($slug);

        if (!$template) {
            Response::notFound('Template non trouvé');
        }

        if ((int) $template['user_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $templateModel->delete((int) $template['id']);

        Response::success(null, 'Template supprimé');
    }

    /**
     * Get all templates for the authenticated user
     */
    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            Response::json(['error' => 'Unauthorized'], 401);
            return;
        }

        $templateModel = new OrderTemplate();
        $templates = $templateModel->getByUser($userId);

        foreach ($templates as &$template) {
            $template = OrderTemplate::sanitizeForResponse($template);
        }

        Response::success($templates);
    }

    /**
     * GET /api/templates/check-slug?slug=boutique-akwa
     * Vérifie si un slug est disponible (public)
     */
    public function checkSlug(Request $request): void
    {
        $slug = strtolower(trim($request->query('slug', '')));

        if (!preg_match('/^[a-z0-9-]{3,50}$/', $slug)) {
            Response::json([
                'success'   => true,
                'available' => false,
                'reason'    => 'Format invalide (lettres minuscules, chiffres, tirets, 3-50 caractères)',
            ]);
            return;
        }

        $templateModel = new OrderTemplate();
        $exists        = $templateModel->findBySlug($slug) !== null;

        Response::json([
            'success'   => true,
            'slug'      => $slug,
            'available' => !$exists,
        ]);
    }

    /**
     * Get a template by slug (Public endpoint)
     */
    public function show(Request $request): void
    {
        $slug = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template = $templateModel->findBySlug($slug);

        if (!$template || !$template['is_active']) {
            Response::json(['error' => 'Template not found or inactive'], 404);
            return;
        }

        $template = OrderTemplate::sanitizeForResponse($template, true);

        Response::success($template);
    }

    /**
     * Instantiate an order from a template (Public endpoint)
     * This creates a DRAFT order immediately
     */
    public function instantiate(Request $request): void
    {
        $slug = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template = $templateModel->findBySlug($slug);

        if (!$template || !$template['is_active']) {
            Response::json(['error' => 'Template not found or inactive'], 404);
            return;
        }

        $defaultValues = isset($template['default_values']) ? json_decode($template['default_values'], true) : [];
        
        $orderModel = new Order();
        $reference = $orderModel->generateReference();

        // Merge default values into order data
        $orderData = array_merge($defaultValues, [
            'reference' => $reference,
            'client_id' => $template['user_id'], 
            'status' => 'pending', 
            'order_type' => 'direct',
            'notes' => ($defaultValues['notes'] ?? '') . " [Template: {$template['name']}]"
        ]);

        // Ensure mandatory fields for DB insert are present or nullable
        // Since we are in draft, we might need to bypass some validation or ensure DB allows nulls.
        // Assuming 'direct' type for now if not specified.
        if (!isset($orderData['order_type'])) {
            $orderData['order_type'] = 'direct';
        }

        // Nettoyer les champs qui n'appartiennent pas à la table orders
        unset($orderData['package_media']);

        try {
            $orderId = $orderModel->create($orderData);
            $orderData['id'] = $orderId;

            // Copier les médias du template (stockés via media_references) vers order_media
            $templateMedia = $defaultValues['package_media'] ?? [];
            if (!empty($templateMedia)) {
                $mediaModel = new OrderMedia();
                foreach ($templateMedia as $m) {
                    $mediaModel->create([
                        'order_id'    => $orderId,
                        'file_name'   => $m['file_name'] ?? basename($m['file_url']),
                        'file_path'   => $m['file_path'],
                        'file_url'    => $m['file_url'],
                        'file_type'   => $m['file_type'] ?? 'image',
                        'mime_type'   => $m['mime_type'] ?? 'image/jpeg',
                        'file_size'   => $m['file_size'] ?? 0,
                        'uploaded_by' => (int) $template['user_id'],
                    ]);
                }
            }

            Response::json([
                'success' => true,
                'message' => 'Order initiated successfully',
                'data' => [
                    'order_reference' => $reference,
                    'order_id'        => $orderId,
                    'media_copied'    => count($templateMedia),
                ]
            ], 201);
        } catch (\Exception $e) {
            Response::json(['error' => 'Failed to create order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/templates/{slug}/media
     * Upload un média de référence sur un template (multipart/form-data, champ "file")
     */
    public function uploadMedia(Request $request): void
    {
        $slug          = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template      = $templateModel->findBySlug($slug);

        if (!$template) {
            Response::notFound('Template non trouvé');
        }

        if ((int) $template['user_id'] !== $this->userId()) {
            Response::forbidden();
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Fichier manquant ou erreur d\'upload', 422);
        }

        $file     = $_FILES['file'];
        $mimeType = mime_content_type($file['tmp_name']);

        if (!array_key_exists($mimeType, OrderMedia::ALLOWED_MIME)) {
            Response::error('Type de fichier non autorisé. Formats acceptés : jpg, png, webp, mp4', 422);
        }

        if ($file['size'] > OrderMedia::MAX_SIZE) {
            Response::error('Fichier trop volumineux (max 10 MB)', 422);
        }

        // Récupérer les médias existants du template
        $defaultValues = json_decode($template['default_values'], true) ?? [];
        $existingMedia = $defaultValues['package_media'] ?? [];

        if (count($existingMedia) >= OrderMedia::MAX_PER_ORDER) {
            Response::error('Maximum ' . OrderMedia::MAX_PER_ORDER . ' médias par template', 422);
        }

        // Stocker le fichier
        $ext      = OrderMedia::ALLOWED_MIME[$mimeType];
        $fileName = uniqid('tmpl_', true) . '.' . $ext;
        $dir      = UPLOAD_DIR . '/templates/' . $template['id'];
        $filePath = $dir . '/' . $fileName;
        $fileUrl  = UPLOAD_URL . '/templates/' . $template['id'] . '/' . $fileName;
        $fileType = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            Response::error('Échec de l\'enregistrement du fichier', 500);
        }

        // Ajouter l'URL dans default_values.package_media
        $existingMedia[] = [
            'file_url'  => $fileUrl,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'mime_type' => $mimeType,
            'file_size' => $file['size'],
        ];
        $defaultValues['package_media'] = $existingMedia;

        $templateModel->update((int) $template['id'], [
            'default_values' => json_encode($defaultValues, JSON_UNESCAPED_UNICODE),
        ]);

        Response::json([
            'success' => true,
            'message' => 'Média ajouté au template',
            'data'    => [
                'file_url'  => $fileUrl,
                'file_type' => $fileType,
                'mime_type' => $mimeType,
                'file_size' => $file['size'],
                'total'     => count($existingMedia),
            ],
        ], 201);
    }

    /**
     * DELETE /api/templates/{slug}/media
     * Supprime un média du template par son URL
     * Body: { "file_url": "..." }
     */
    public function deleteMedia(Request $request): void
    {
        $slug          = $request->param('slug');
        $templateModel = new OrderTemplate();
        $template      = $templateModel->findBySlug($slug);

        if (!$template) {
            Response::notFound('Template non trouvé');
        }

        if ((int) $template['user_id'] !== $this->userId()) {
            Response::forbidden();
        }

        $fileUrl       = $request->input('file_url');
        $defaultValues = json_decode($template['default_values'], true) ?? [];
        $media         = $defaultValues['package_media'] ?? [];

        $found = false;
        $media = array_filter($media, function ($m) use ($fileUrl, &$found) {
            if ($m['file_url'] === $fileUrl) {
                // Supprimer le fichier physique
                if (!empty($m['file_path']) && file_exists($m['file_path'])) {
                    unlink($m['file_path']);
                }
                $found = true;
                return false;
            }
            return true;
        });

        if (!$found) {
            Response::notFound('Média non trouvé dans ce template');
        }

        $defaultValues['package_media'] = array_values($media);
        $templateModel->update((int) $template['id'], [
            'default_values' => json_encode($defaultValues, JSON_UNESCAPED_UNICODE),
        ]);

        Response::success(null, 'Média supprimé du template');
    }
}
