#!/usr/bin/env php
<?php

/**
 * Script de réconciliation des paiements
 * Vérifie les transactions en attente et met à jour leur statut
 * 
 * Usage: php reconcile_payments.php [--hours=24] [--dry-run]
 */

// Définir les constantes nécessaires
define('PUBLIC_PATH', __DIR__ . '/../../public_html');
define('PRIVATE_PATH', __DIR__ . '/..');

// Charger la configuration
$configFile = __DIR__ . '/../app/Config/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Core/Model.php';
require_once __DIR__ . '/../app/Models/PaymentProvider.php';
require_once __DIR__ . '/../app/Models/PaymentTransaction.php';
require_once __DIR__ . '/../app/Services/Payment/PaymentProviderInterface.php';
require_once __DIR__ . '/../app/Services/Payment/CamPayProvider.php';
require_once __DIR__ . '/../app/Services/Payment/CashProvider.php';
require_once __DIR__ . '/../app/Services/PaymentService.php';

use App\Models\PaymentTransaction;
use App\Services\PaymentService;

// Parse arguments
$options = getopt('', ['hours:', 'dry-run']);
$hours = isset($options['hours']) ? (int)$options['hours'] : 24;
$dryRun = isset($options['dry-run']);

echo "=== Réconciliation des paiements ===\n";
echo "Période: Dernières {$hours} heures\n";
echo "Mode: " . ($dryRun ? "DRY RUN (simulation)" : "PRODUCTION") . "\n\n";

$txModel = new PaymentTransaction();
$service = new PaymentService();

// Récupérer les transactions en attente
$db = \App\Config\Database::getInstance();
$stmt = $db->prepare("
    SELECT * FROM payment_transactions 
    WHERE status IN ('pending', 'processing')
    AND initiated_at > DATE_SUB(NOW(), INTERVAL :hours HOUR)
    ORDER BY initiated_at DESC
");
$stmt->execute(['hours' => $hours]);
$transactions = $stmt->fetchAll();

echo "Transactions trouvées: " . count($transactions) . "\n\n";

$stats = [
    'checked' => 0,
    'completed' => 0,
    'failed' => 0,
    'still_pending' => 0,
    'errors' => 0,
];

foreach ($transactions as $transaction) {
    $stats['checked']++;
    
    echo "[{$transaction['reference']}] ";
    echo "Initié: {$transaction['initiated_at']} | ";
    echo "Statut actuel: {$transaction['status']} | ";
    
    try {
        // Vérifier le statut auprès du provider
        $result = $service->checkPaymentStatus($transaction['reference']);
        
        if ($result['success']) {
            $newStatus = $result['transaction']['status'];
            echo "Nouveau statut: {$newStatus}";
            
            if ($newStatus === 'completed') {
                $stats['completed']++;
                echo " ✅";
            } elseif ($newStatus === 'failed') {
                $stats['failed']++;
                echo " ❌";
            } else {
                $stats['still_pending']++;
                echo " ⏳";
            }
            
            if ($dryRun) {
                echo " (simulation - pas de mise à jour)";
            }
        } else {
            echo "Erreur: {$result['message']}";
            $stats['errors']++;
        }
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage();
        $stats['errors']++;
    }
    
    echo "\n";
}

echo "\n=== Résumé ===\n";
echo "Vérifiées:        {$stats['checked']}\n";
echo "Complétées:       {$stats['completed']} ✅\n";
echo "Échouées:         {$stats['failed']} ❌\n";
echo "Toujours pending: {$stats['still_pending']} ⏳\n";
echo "Erreurs:          {$stats['errors']}\n";

exit(0);
