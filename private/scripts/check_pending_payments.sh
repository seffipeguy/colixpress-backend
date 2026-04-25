#!/bin/bash

# Script de vérification des paiements en attente
# Usage: ./check_pending_payments.sh [hours]

HOURS=${1:-24}

echo "=== Vérification des paiements en attente ==="
echo "Période: Dernières $HOURS heures"
echo ""

sudo mariadb -u root colixpress_db << EOF
SELECT 
    reference,
    status,
    amount,
    customer_phone,
    provider_transaction_id,
    TIMESTAMPDIFF(MINUTE, initiated_at, NOW()) as minutes_ago,
    initiated_at
FROM payment_transactions 
WHERE status IN ('pending', 'processing')
AND initiated_at > DATE_SUB(NOW(), INTERVAL $HOURS HOUR)
ORDER BY initiated_at DESC;
EOF

echo ""
echo "=== Résumé ==="
sudo mariadb -u root colixpress_db << EOF
SELECT 
    status,
    COUNT(*) as count,
    SUM(amount) as total_amount
FROM payment_transactions 
WHERE status IN ('pending', 'processing')
AND initiated_at > DATE_SUB(NOW(), INTERVAL $HOURS HOUR)
GROUP BY status;
EOF
