# Protections du système de paiement

## 🛡️ Protections implémentées

### 1. Protection contre les webhooks tardifs

**Problème** : CamPay peut envoyer plusieurs webhooks pour la même transaction, parfois avec des statuts contradictoires (SUCCESSFUL puis FAILED).

**Solution** : Si une transaction est déjà `completed`, les webhooks ultérieurs avec un statut différent sont ignorés.

**Code** : `PaymentController::webhook()`
```php
if ($transaction['status'] === 'completed' && $status !== 'SUCCESSFUL') {
    // Ignorer le webhook tardif
    return success('Webhook ignored - transaction already completed');
}
```

**Log** : Les webhooks ignorés sont loggés dans `/tmp/webhook_campay.log`

---

### 2. Script de vérification des paiements en attente

**Fichier** : `/private/scripts/check_pending_payments.sh`

**Usage** :
```bash
# Vérifier les paiements des dernières 24h
./check_pending_payments.sh 24

# Vérifier les paiements des dernières 2h
./check_pending_payments.sh 2
```

**Résultat** : Affiche toutes les transactions en `pending` ou `processing` avec leur durée.

---

### 3. API Admin de réconciliation

#### 3.1 Vérifier une transaction spécifique

```http
POST /api/admin/payment/transactions/{reference}/check-status
Authorization: Bearer {admin_token}
```

**Exemple** :
```bash
POST /api/admin/payment/transactions/TX-20260425-999DA8F8/check-status
```

**Réponse** :
```json
{
  "success": true,
  "message": "Status checked successfully",
  "data": {
    "transaction": {
      "reference": "TX-20260425-999DA8F8",
      "status": "completed",
      "amount": 25
    }
  }
}
```

#### 3.2 Réconcilier toutes les transactions en attente

```http
POST /api/admin/payment/reconcile
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "hours": 24
}
```

**Réponse** :
```json
{
  "success": true,
  "message": "Reconciliation completed",
  "data": {
    "checked": 5,
    "completed": 2,
    "failed": 1,
    "still_pending": 2,
    "errors": 0,
    "details": [
      {
        "reference": "TX-20260425-ABC123",
        "old_status": "processing",
        "new_status": "completed"
      }
    ]
  }
}
```

---

## 🔄 Flux de réconciliation recommandé

### Automatique (Cron)

Ajouter dans crontab :
```bash
# Vérifier les paiements en attente toutes les heures
0 * * * * /path/to/check_pending_payments.sh 2 >> /var/log/payment_check.log 2>&1
```

### Manuel (Admin)

1. **Consulter les transactions en attente** :
   ```
   GET /api/admin/payment/transactions?status=processing
   ```

2. **Lancer la réconciliation** :
   ```
   POST /api/admin/payment/reconcile
   Body: {"hours": 24}
   ```

3. **Vérifier une transaction spécifique** :
   ```
   POST /api/admin/payment/transactions/TX-xxx/check-status
   ```

---

## 📊 Cas d'usage

### Cas 1 : Transaction sans webhook
```
Problème : Paiement validé mais webhook jamais reçu
Solution : Lancer la réconciliation qui va vérifier auprès de CamPay
Résultat : Transaction mise à jour, wallet crédité
```

### Cas 2 : Webhook tardif contradictoire
```
Problème : Webhook SUCCESSFUL reçu, wallet crédité, puis webhook FAILED 30min après
Solution : Protection automatique ignore le 2e webhook
Résultat : Transaction reste "completed", wallet intact
```

### Cas 3 : Paiement bloqué en "processing"
```
Problème : Transaction en "processing" depuis 2h
Solution : Admin vérifie le statut manuellement
Résultat : Si payé chez CamPay → completed, sinon → failed
```

---

## 🎯 Résumé des protections

| Protection | Type | Automatique | Manuel |
|------------|------|-------------|--------|
| Anti-webhook tardif | Code | ✅ | - |
| Script vérification | Shell | ⏰ Cron | ✅ |
| API réconciliation | Endpoint | - | ✅ Admin |
| Conversion timezone | Code | ✅ | - |

---

## 📝 Logs

Tous les webhooks sont loggés dans :
```
/tmp/webhook_campay.log
```

Format :
```
2026-04-25 09:17:33 - Webhook reçu
{...payload...}
✅ Transaction TX-xxx marquée comme COMPLETED
💰 Wallet crédité: 25 XAF (solde: 100 → 125) pour user #4
🔔 Notification envoyée à user #4
```

Ou en cas de webhook tardif :
```
⚠️ Webhook tardif ignoré: Transaction déjà completed, webhook dit FAILED
```
