# ColiXpress API Documentation

**Base URL:** `http://api.colixpress.com`  
**Version:** 1.0.0  
**Format:** JSON  
**Authentification:** Bearer Token via header `Authorization: Bearer <token>`

---

## Table des matières

1. [Généralités](#1-généralités)
2. [Authentification — OTP](#2-authentification--otp)
3. [Authentification — Mot de passe](#3-authentification--mot-de-passe)
4. [Profil utilisateur](#4-profil-utilisateur)
5. [Adresses](#5-adresses)
6. [Commandes](#6-commandes)
7. [Boutiques](#7-boutiques)
8. [Articles de boutique](#8-articles-de-boutique)
9. [Livreur](#9-livreur)
10. [Notifications](#10-notifications)
11. [Évaluations (Ratings)](#11-évaluations-ratings)
12. [Tarification](#12-tarification)
13. [Paramètres (Settings)](#13-paramètres-settings)
14. [Promotions](#14-promotions)
15. [Bannières / Actualités](#15-bannières--actualités)
16. [Maps — Proxy Cache Google Maps](#16-maps--proxy-cache-google-maps)
17. [API Développeur](#17-api-développeur)

---

## 1. Généralités

### Format des réponses

Toutes les réponses suivent ce format :

```json
{
  "success": true,
  "message": "Description",
  "data": { ... }
}
```

**Erreur :**
```json
{
  "success": false,
  "message": "Description de l'erreur"
}
```

**Réponse paginée :**
```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "total": 50,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

### Codes HTTP

| Code | Signification |
|------|--------------|
| 200 | Succès |
| 201 | Ressource créée |
| 401 | Non authentifié / Token invalide |
| 403 | Accès interdit (rôle insuffisant) |
| 404 | Ressource introuvable |
| 409 | Conflit (doublon) |
| 422 | Données invalides |
| 500 | Erreur serveur |

### Rôles utilisateur

| Rôle | Description |
|------|------------|
| `client` | Utilisateur standard (par défaut) |
| `livreur` | Livreur / coursier |
| `shop_owner` | Propriétaire de boutique |
| `admin` | Administrateur |

### Pagination

Tous les endpoints paginés acceptent ces query params :
- `page` — numéro de page (défaut : 1)
- `per_page` — éléments par page (défaut : 20, max : 100)

---

## 2. Authentification — OTP

### `GET /api/health` 🟢 Public

Vérifier que l'API fonctionne.

**Réponse :**
```json
{
  "success": true,
  "data": {
    "app": "ColiXpress API",
    "version": "1.0.0",
    "status": "running",
    "time": "2026-02-08 14:55:31"
  }
}
```

---

### `GET /api/countries` 🟢 Public

Liste des pays supportés avec indicatifs téléphoniques.

**Réponse :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Cameroun",
      "iso_code": "CM",
      "dial_code": "+237",
      "phone_length": 9,
      "currency": "XAF",
      "is_active": 1
    }
  ]
}
```

---

### `POST /api/auth/send-otp` 🟢 Public

Envoyer un code OTP par SMS.

**Body :**
```json
{
  "country_id": 1,
  "phone": "691234567"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `country_id` | int | ✅ | ID du pays |
| `phone` | string | ✅ | Numéro sans indicatif |

**Réponse (200) :**
```json
{
  "success": true,
  "message": "OTP sent",
  "data": {
    "message": "OTP sent successfully",
    "expires_in": "5 minutes",
    "otp_code": "9833"
  }
}
```

> ⚠️ `otp_code` n'est retourné qu'en mode **development**. En production, le code est envoyé par SMS uniquement.

---

### `POST /api/auth/verify-otp` 🟢 Public

Vérifier le code OTP. Crée le compte si l'utilisateur n'existe pas.

**Body :**
```json
{
  "country_id": 1,
  "phone": "691234567",
  "code": "9833"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `country_id` | int | ✅ | ID du pays |
| `phone` | string | ✅ | Numéro de téléphone |
| `code` | string | ✅ | Code OTP reçu |

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Account created",
  "data": {
    "token": "09e83c6ee475...e0c8a",
    "user": {
      "id": 1,
      "country_id": 1,
      "phone": "691234567",
      "role": "client",
      "first_name": null,
      "last_name": null,
      "email": null,
      "profile_photo": null,
      "is_verified": 1,
      "created_at": "2026-02-08 14:55:46",
      "updated_at": "2026-02-08 14:55:46",
      "dial_code": "+237",
      "country_code": "CM",
      "country_name": "Cameroun"
    },
    "is_new": true
  }
}
```

---

## 3. Authentification — Mot de passe

### `POST /api/auth/register` 🟢 Public

Créer un compte avec téléphone + mot de passe.

**Body :**
```json
{
  "country_id": 1,
  "phone": "699000111",
  "password": "MonPass123",
  "first_name": "Marie",
  "last_name": "Ngo"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `country_id` | int | ✅ | ID du pays |
| `phone` | string | ✅ | Numéro de téléphone |
| `password` | string | ✅ | Mot de passe (min 6 car.) |
| `first_name` | string | ❌ | Prénom |
| `last_name` | string | ❌ | Nom |

**Réponse (201) :**
```json
{
  "success": true,
  "message": "Account registered",
  "data": {
    "token": "d840c877...c36",
    "user": {
      "id": 2,
      "phone": "699000111",
      "role": "client",
      "first_name": "Marie",
      "last_name": "Ngo",
      "is_verified": 0,
      "dial_code": "+237",
      "country_code": "CM",
      "country_name": "Cameroun"
    }
  }
}
```

> 💡 Un compte créé via OTP (sans password) peut appeler `register` pour ajouter un mot de passe.  
> ⚠️ `is_verified` est à `0` tant que l'utilisateur n'a pas vérifié son téléphone via OTP.

**Erreurs :**
- `409` — Compte avec mot de passe existant
- `422` — Téléphone invalide, mot de passe trop court

---

### `POST /api/auth/login` 🟢 Public

Se connecter avec téléphone + mot de passe.

**Body :**
```json
{
  "country_id": 1,
  "phone": "699000111",
  "password": "MonPass123"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `country_id` | int | ✅ | ID du pays |
| `phone` | string | ✅ | Numéro de téléphone |
| `password` | string | ✅ | Mot de passe |

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "59d91c4c...965",
    "user": { ... }
  }
}
```

**Erreurs :**
- `401` — Téléphone ou mot de passe incorrect
- `401` — Aucun mot de passe défini (utiliser OTP ou register d'abord)
- `403` — Compte désactivé

---

### `PUT /api/auth/password` 🔒 Auth requise

Modifier ou définir son mot de passe.

**Headers :** `Authorization: Bearer <token>`

**Body :**
```json
{
  "current_password": "MonPass123",
  "new_password": "NewPass456"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `current_password` | string | Conditionnel | Requis si un mot de passe existe déjà |
| `new_password` | string | ✅ | Nouveau mot de passe (min 6 car.) |

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Password updated"
}
```

---

### `POST /api/auth/logout` 🔒 Auth requise

Se déconnecter (révoquer le token).

**Headers :** `Authorization: Bearer <token>`

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Logged out successfully"
}
```

---

### `GET /api/auth/me` 🔒 Auth requise

Récupérer le profil de l'utilisateur connecté.

**Headers :** `Authorization: Bearer <token>`

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "country_id": 1,
    "phone": "691234567",
    "role": "client",
    "first_name": "Jean",
    "last_name": "Kamga",
    "email": "jean@test.cm",
    "profile_photo": null,
    "is_verified": 1,
    "dial_code": "+237",
    "country_code": "CM",
    "country_name": "Cameroun"
  }
}
```

---

## 4. Profil utilisateur

> Tous les endpoints de cette section nécessitent `Authorization: Bearer <token>`

### `GET /api/user/profile` 🔒

Récupérer le profil complet. Identique à `GET /api/auth/me`.

---

### `PUT /api/user/profile` 🔒

Modifier le profil.

**Body :**
```json
{
  "first_name": "Jean",
  "last_name": "Kamga",
  "email": "jean@example.com"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `first_name` | string | ❌ | Prénom |
| `last_name` | string | ❌ | Nom |
| `email` | string | ❌ | Email (format validé) |

**Réponse (200) :** Profil mis à jour.

---

### `POST /api/user/profile-photo` 🔒

Uploader une photo de profil.

**Content-Type :** `multipart/form-data`

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `profile_photo` | file | ✅ | Image JPG, PNG ou WebP (max 5 MB) |

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Photo updated",
  "data": {
    "profile_photo": "/uploads/profiles/user_1_1707400000.jpg"
  }
}
```

---

### `DELETE /api/user/account` 🔒

Désactiver le compte (soft delete).

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Account deactivated"
}
```

---

## 5. Adresses

> 🔒 Tous les endpoints nécessitent un token. Un utilisateur ne peut voir/modifier que ses propres adresses.

### `GET /api/addresses` 🔒

Lister ses adresses.

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 1,
      "label": "Maison",
      "full_address": "Rue Besseke, Akwa, Douala",
      "latitude": "4.05110000",
      "longitude": "9.76790000",
      "city": "Douala",
      "quarter": "Akwa",
      "is_default": 0
    }
  ]
}
```

---

### `POST /api/addresses` 🔒

Créer une adresse.

**Body :**
```json
{
  "label": "Maison",
  "full_address": "Rue Besseke, Akwa, Douala",
  "latitude": 4.0511,
  "longitude": 9.7679,
  "city": "Douala",
  "quarter": "Akwa",
  "is_default": 0
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `label` | string | ✅ | Libellé (Maison, Bureau...) |
| `full_address` | string | ✅ | Adresse complète |
| `latitude` | float | ❌ | Latitude GPS |
| `longitude` | float | ❌ | Longitude GPS |
| `city` | string | ❌ | Ville (défaut : Douala) |
| `quarter` | string | ❌ | Quartier |
| `is_default` | int | ❌ | 1 = adresse par défaut |

**Réponse (201) :** Adresse créée.

---

### `GET /api/addresses/{id}` 🔒

Voir une adresse.

---

### `PUT /api/addresses/{id}` 🔒

Modifier une adresse. Mêmes champs que la création (tous optionnels).

---

### `DELETE /api/addresses/{id}` 🔒

Supprimer une adresse.

---

## 6. Commandes

> 🔒 Tous les endpoints nécessitent un token.

### `POST /api/orders` 🔒

Créer une commande de livraison.

> **Note :** La création de commande accepte désormais des données incomplètes. Les champs obligatoires peuvent être omis lors de la création et complétés plus tard via `PUT /api/orders/{id}`.

#### Type `direct` — Livraison point à point

**Body :**
```json
{
  "pickup_address": "Akwa, Douala",
  "pickup_lat": 4.0511,
  "pickup_lng": 9.7679,
  "pickup_contact_name": "Jean",
  "pickup_contact_phone": "+237691234567",
  "dropoff_address": "Bonaberi, Douala",
  "dropoff_lat": 4.0611,
  "dropoff_lng": 9.7879,
  "dropoff_contact_name": "Marie",
  "dropoff_contact_phone": "+237699887766",
  "package_description": "Petit carton documents",
  "package_size": "petit",
  "payment_method": "cash"
}
```

#### Type `shop` — Commande depuis une boutique

**Body :**
```json
{
  "order_type": "shop",
  "shop_id": 1,
  "dropoff_address": "Bonaberi, Douala",
  "dropoff_lat": 4.0611,
  "dropoff_lng": 9.7879,
  "dropoff_contact_name": "Marie",
  "dropoff_contact_phone": "+237699887766",
  "payment_method": "cash",
  "items": [
    { "shop_item_id": 1, "quantity": 2 },
    { "shop_item_id": 3, "quantity": 1 }
  ]
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `order_type` | string | ❌ | `direct` (défaut) ou `shop` |
| `pickup_address` | string | ❌ | Adresse d'enlèvement (optionnel à la création) |
| `pickup_lat` | float | ❌ | Latitude enlèvement |
| `pickup_lng` | float | ❌ | Longitude enlèvement |
| `pickup_contact_name` | string | ❌ | Nom du contact enlèvement |
| `pickup_contact_phone` | string | ❌ | Téléphone contact enlèvement |
| `dropoff_address` | string | ❌ | Adresse de livraison (optionnel à la création) |
| `dropoff_lat` | float | ❌ | Latitude livraison |
| `dropoff_lng` | float | ❌ | Longitude livraison |
| `dropoff_contact_name` | string | ❌ | Nom du destinataire |
| `dropoff_contact_phone` | string | ❌ | Téléphone destinataire |
| `package_description` | string | ❌ | Description du colis |
| `package_size` | string | ❌ | `petit`, `moyen`, `grand` |
| `package_weight_kg` | float | ❌ | Poids en kg |
| `package_value` | int | ❌ | Valeur estimée du colis en XAF (surcharge si > seuil) |
| `maps_usage` | object | ❌ | Compteur d'appels Maps (ex: `{"autocomplete":3,"geocode":2,"directions":1}`) |
| `payment_method` | string | ❌ | `cash` (défaut), `mobile_money` |
| `notes` | string | ❌ | Instructions particulières |
| `pickup_scheduled_at` | datetime | ❌ | Créneau horaire souhaité pour le ramassage (format: `Y-m-d H:i:s`) |
| `scheduled_at` | datetime | ❌ | Créneau horaire souhaité pour la livraison (format: `Y-m-d H:i:s`) |
| `shop_id` | int | ✅ shop | ID de la boutique |
| `items` | array | ❌ shop | Articles commandés |

> **Frais Maps API :** Le frontend compte les appels Maps effectués pendant la saisie de la commande et envoie le compteur dans `maps_usage`. Le backend calcule automatiquement le coût et l'ajoute au prix total. Voir `GET /api/settings/maps-pricing` pour les tarifs.

**Réponse (201) :**
```json
{
  "success": true,
  "message": "Order created",
  "data": {
    "id": 1,
    "reference": "COL-2026-9DBA16",
    "order_type": "direct",
    "status": "pending",
    "price": 1000,
    "currency": "XAF",
    "distance_km": "2.48",
    "pickup_address": "Akwa, Douala",
    "dropoff_address": "Bonaberi, Douala",
    "...": "..."
  }
}
```

**Corps de la requête (Exemple minimal sans adresse) :**
```json
{
  "package_description": "Un colis à livrer plus tard"
}
```

**Réponse (201) :**
```json
{
  "success": true,
  "data": {
    "id": 124,
    "reference": "CMD-20260222-WXYZ",
    "status": "pending",
    "pickup_address": null,
    "dropoff_address": null,
    "price": 1000,
    "created_at": "2026-02-22 12:05:00"
  },
  "message": "Order created"
}
```

---

### `GET /api/orders` 🔒

Lister ses commandes.

**Query params :**
- `status` — Filtrer par statut (optionnel)
- `page`, `per_page`

> **Comportement par rôle :**
> - `client` → voit ses propres commandes
> - `livreur` → voit ses commandes assignées
> - `admin` → voit toutes les commandes

---

### `GET /api/orders/pending` 🔒 Livreur/Admin

Voir les commandes en attente d'un livreur.

---

### `GET /api/orders/estimate` 🔒

Estimer le prix d'une livraison. Prend en compte la distance, la taille, le poids et la valeur déclarée du colis.

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `pickup_lat` | float | ✅ | Latitude départ |
| `pickup_lng` | float | ✅ | Longitude départ |
| `dropoff_lat` | float | ✅ | Latitude arrivée |
| `dropoff_lng` | float | ✅ | Longitude arrivée |
| `city` | string | ❌ | Ville (défaut : Douala) |
| `package_size` | string | ❌ | `petit`, `moyen`, `grand` |
| `package_weight_kg` | float | ❌ | Poids en kg |
| `package_value` | int | ❌ | Valeur estimée du colis (XAF) |

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "distance_km": 2.48,
    "price": 2496,
    "currency": "XAF",
    "city": "Douala",
    "value_surcharge": 1500
  }
}
```

> `value_surcharge` n'apparaît que si > 0. Surcharge = `package_value_surcharge_percent`% de la valeur déclarée, au-delà de `package_value_threshold` XAF, plafonné à `package_value_max_surcharge` XAF. Ces paramètres sont configurables via les Settings admin.

---

### `GET /api/orders/frequent-places` 🔒

Lieux de ramassage et de livraison les plus fréquemment utilisés par l'utilisateur, basé sur son historique de commandes. Utile pour l'auto-complétion dans l'app mobile.

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `limit` | int | ❌ | Nombre max de lieux par catégorie (défaut : 5, max : 20) |

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "pickup": [
      {
        "address": "Akwa, Douala",
        "latitude": "4.05110000",
        "longitude": "9.76790000",
        "contact_name": "Mon Bureau",
        "contact_phone": "+237691000000",
        "usage_count": 12,
        "last_used": "2026-02-15 10:30:00"
      }
    ],
    "dropoff": [
      {
        "address": "Bonaberi, Douala",
        "latitude": "4.06110000",
        "longitude": "9.78790000",
        "contact_name": "Maison",
        "contact_phone": "+237699888777",
        "usage_count": 8,
        "last_used": "2026-02-14 18:00:00"
      }
    ]
  }
}
```

---

### `GET /api/orders/frequent-shops` 🔒

Boutiques où l'utilisateur commande le plus souvent. Utile pour afficher une section "Vos boutiques favorites" dans l'app.

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `limit` | int | ❌ | Nombre max de boutiques (défaut : 5, max : 20) |

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "Pizza House Akwa",
      "address": "Rue Joss, Akwa, Douala",
      "latitude": "4.04920000",
      "longitude": "9.70520000",
      "phone": "+237699111222",
      "logo": "/uploads/shops/pizza_house.jpg",
      "cover_photo": null,
      "category_name": "Restaurant",
      "order_count": 8,
      "total_spent": 45000,
      "last_order_at": "2026-02-14 19:30:00"
    }
  ]
}
```

---

### `GET /api/orders/{id}` 🔒

Détail d'une commande (inclut `status_history` et `items` pour les commandes shop).

> Accès : client de la commande, livreur assigné, ou admin.

---

### `PUT /api/orders/{id}` 🟢 Public

Mettre à jour les informations d'une commande existante.

> **Accès :** Public (aucune authentification requise).
> **Condition :** La commande doit être au statut `pending`. Le prix est automatiquement recalculé si les adresses ou les informations du colis changent.

**Body :**
Tous les champs sont optionnels (voir `POST /api/orders` pour la liste complète).
```json
{
  "pickup_address": "Bali, Douala",
  "pickup_lat": 4.0450,
  "pickup_lng": 9.7000,
  "dropoff_address": "Bonanjo, Douala",
  "notes": "Changement d'adresse de livraison",
  "package_size": "moyen",
  "pickup_scheduled_at": "2026-03-01 10:00:00"
}
```

**Réponse (200) :**
Retourne l'objet commande mis à jour.

---

### `PUT /api/orders/{id}/accept` 🔒 Livreur

Le livreur accepte une commande.

> La commande doit être en statut `pending`.

**Réponse (200) :** Commande avec le livreur assigné.

---

### `PUT /api/orders/{id}/status` 🔒 Livreur

Mettre à jour le statut de la commande.

**Body :**
```json
{
  "status": "picked_up",
  "comment": "Colis récupéré"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `status` | string | ✅ | Nouveau statut |
| `comment` | string | ❌ | Commentaire |

**Transitions autorisées :**

```
pending → (accept) → accepted
accepted → picking_up → picked_up → in_transit → delivered
                                                 → cancelled (à chaque étape)
```

---

### `PUT /api/orders/{id}/cancel` 🔒 Client

Annuler une commande.

**Body :**
```json
{
  "cancellation_reason": "Je n'ai plus besoin"
}
```

> Annulation possible uniquement si statut = `pending` ou `accepted`.

---

### `GET /api/orders/{id}/tracking` 🔒

Suivre la position GPS du livreur pour une commande.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "current_position": {
      "latitude": "4.05110000",
      "longitude": "9.76790000",
      "updated_at": "2026-02-08 15:30:00"
    },
    "trail": [
      {
        "latitude": "4.05100000",
        "longitude": "9.76780000",
        "recorded_at": "2026-02-08 15:28:00"
      }
    ]
  }
}
```

---

## 7. Boutiques

### `GET /api/shops` 🟢 Public

Parcourir les boutiques approuvées.

**Query params :**
- `category_id` — Filtrer par catégorie
- `city` — Filtrer par ville
- `page`, `per_page`

---

### `GET /api/shops/{id}` 🟢 Public

Détail d'une boutique (inclut la liste de ses articles).

---

### `GET /api/shop-categories` 🟢 Public

Liste des catégories de boutiques.

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Restaurant", "icon": null, "sort_order": 1 },
    { "id": 2, "name": "Pharmacie", "icon": null, "sort_order": 2 },
    { "id": 3, "name": "Supermarche", "icon": null, "sort_order": 3 },
    { "id": 4, "name": "Boutique Mode", "icon": null, "sort_order": 4 },
    { "id": 5, "name": "Electronique", "icon": null, "sort_order": 5 },
    { "id": 6, "name": "Boulangerie / Patisserie", "icon": null, "sort_order": 6 },
    { "id": 7, "name": "Librairie", "icon": null, "sort_order": 7 },
    { "id": 8, "name": "Autre", "icon": null, "sort_order": 99 }
  ]
}
```

---

### `GET /api/shops/popular` 🟢 Public

Classement global des boutiques les plus commandées sur la plateforme. Idéal pour la page d'accueil ("Boutiques populaires").

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `limit` | int | ❌ | Nombre max (défaut : 10, max : 50) |
| `category_id` | int | ❌ | Filtrer par catégorie |
| `city` | string | ❌ | Filtrer par ville |

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "Pizza House Akwa",
      "address": "Rue Joss, Akwa, Douala",
      "city": "Douala",
      "latitude": "4.04920000",
      "longitude": "9.70520000",
      "phone": "+237699111222",
      "logo": "/uploads/shops/pizza_house.jpg",
      "cover_photo": null,
      "category_name": "Restaurant",
      "total_orders": 156,
      "unique_clients": 89,
      "avg_rating": 4.3,
      "total_ratings": 42
    }
  ]
}
```

| Champ | Description |
|-------|-------------|
| `total_orders` | Nombre total de commandes (hors annulées) |
| `unique_clients` | Nombre de clients distincts |
| `avg_rating` | Note moyenne (0 si aucune évaluation) |
| `total_ratings` | Nombre d'évaluations |

---

## 8. Configuration App

### `GET /api/settings/app-version` 🟢 Public

Vérifier la version de l'application et les mises à jour requises.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "android": {
      "latest_version": "1.2.0",
      "min_version": "1.1.0",
      "url": "https://play.google.com/store/apps/details?id=com.colixpress",
      "deadline": "2026-03-01"
    },
    "ios": {
      "latest_version": "1.0.5",
      "min_version": "1.0.0",
      "url": "https://apps.apple.com/app/id123456789",
      "deadline": null
    },
    "message": "Une nouvelle version est disponible."
  }
}
```

> **Logique Client suggérée :**
> 1. Si `current_version` < `min_version` → **Blocage** (Mise à jour obligatoire).
> 2. Si `current_version` < `latest_version` ET `today` > `deadline` → **Blocage** (Mise à jour obligatoire).
> 3. Si `current_version` < `latest_version` → **Soft Update** (Popup fermable).

---

---

### `POST /api/shops` 🔒 Shop Owner / Admin

Créer une boutique.

**Body :**
```json
{
  "name": "Restaurant Chez Mama",
  "description": "Cuisine camerounaise traditionnelle",
  "category_id": 1,
  "address": "Rue de la Joie, Akwa, Douala",
  "latitude": 4.0520,
  "longitude": 9.7690,
  "city": "Douala",
  "quarter": "Akwa",
  "country_id": 1,
  "phone": "699111222",
  "opening_time": "08:00",
  "closing_time": "22:00"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `name` | string | ✅ | Nom de la boutique |
| `description` | string | ❌ | Description |
| `category_id` | int | ❌ | ID catégorie |
| `address` | string | ✅ | Adresse physique |
| `latitude` | float | ❌ | Latitude GPS |
| `longitude` | float | ❌ | Longitude GPS |
| `city` | string | ❌ | Ville (défaut : Douala) |
| `quarter` | string | ❌ | Quartier |
| `country_id` | int | ✅ | ID du pays |
| `phone` | string | ✅ | Téléphone de la boutique |
| `opening_time` | string | ❌ | Heure d'ouverture (HH:MM) |
| `closing_time` | string | ❌ | Heure de fermeture (HH:MM) |

> La boutique est créée avec `is_approved = 0`. Un admin doit l'approuver.

---

### `PUT /api/shops/{id}` 🔒 Owner / Admin

Modifier une boutique. Mêmes champs que la création (tous optionnels).

---

### `GET /api/shops/my` 🔒 Shop Owner

Voir ses propres boutiques.

---

### `PUT /api/shops/{id}/approve` 🔒 Admin

Approuver une boutique.

---

## 8. Articles de boutique

### `GET /api/shops/{shop_id}/items` 🟢 Public

Lister les articles d'une boutique.

---

### `POST /api/shops/{shop_id}/items` 🔒 Owner / Admin

Ajouter un article.

**Body :**
```json
{
  "name": "Poulet DG",
  "description": "Poulet directeur général avec plantain",
  "price": 3500,
  "photo": "https://...",
  "category": "Plats",
  "is_available": 1,
  "sort_order": 1
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `name` | string | ✅ | Nom de l'article |
| `description` | string | ❌ | Description |
| `price` | int | ✅ | Prix en monnaie locale |
| `photo` | string | ❌ | URL de la photo |
| `category` | string | ❌ | Catégorie interne |
| `is_available` | int | ❌ | 1 = disponible (défaut) |
| `sort_order` | int | ❌ | Ordre d'affichage |

---

### `PUT /api/shops/{shop_id}/items/{id}` 🔒 Owner / Admin

Modifier un article. Mêmes champs (tous optionnels).

---

### `DELETE /api/shops/{shop_id}/items/{id}` 🔒 Owner / Admin

Supprimer un article.

---

## 9. Livreur

### `POST /api/livreur/register` 🔒

S'inscrire comme livreur. Change le rôle de l'utilisateur en `livreur`.

**Body :**
```json
{
  "vehicle_type": "moto",
  "plate_number": "LT-1234-AB",
  "id_card_number": "123456789",
  "id_card_photo": "https://..."
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `vehicle_type` | string | ✅ | `moto`, `voiture`, `velo`, `pied` |
| `plate_number` | string | ❌ | Numéro de plaque |
| `id_card_number` | string | ❌ | Numéro CNI |
| `id_card_photo` | string | ❌ | URL photo CNI |

> Le profil est créé avec `is_approved = 0`. Un admin doit l'approuver.

---

### `GET /api/livreur/profile` 🔒 Livreur

Voir son profil livreur.

---

### `PUT /api/livreur/profile` 🔒 Livreur

Modifier son profil. Champs modifiables : `vehicle_type`, `plate_number`, `id_card_number`, `id_card_photo`.

---

### `PUT /api/livreur/availability` 🔒 Livreur

Activer/désactiver sa disponibilité.

**Body :**
```json
{
  "is_available": 1
}
```

> ⚠️ Le profil doit être approuvé par un admin avant de pouvoir se mettre disponible.

---

### `POST /api/livreur/location` 🔒 Livreur

Mettre à jour sa position GPS (appelé régulièrement par l'app).

**Body :**
```json
{
  "latitude": 4.0511,
  "longitude": 9.7679,
  "order_id": 12
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `latitude` | float | ✅ | Latitude |
| `longitude` | float | ✅ | Longitude |
| `order_id` | int | ❌ | ID commande en cours (pour le tracking) |

---

### `GET /api/livreur/nearby` 🔒

Trouver les livreurs disponibles à proximité.

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `lat` | float | ✅ | Latitude |
| `lng` | float | ✅ | Longitude |
| `radius` | float | ❌ | Rayon en km (défaut : 5) |

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "user_id": 5,
      "vehicle_type": "moto",
      "current_lat": "4.05110000",
      "current_lng": "9.76790000",
      "distance_km": 0.35,
      "first_name": "Paul",
      "last_name": "Mbarga",
      "phone": "677123456"
    }
  ]
}
```

---

### `PUT /api/livreur/{id}/approve` 🔒 Admin

Approuver un profil livreur.

---

## 10. Notifications

> 🔒 Tous les endpoints nécessitent un token.

### `GET /api/notifications` 🔒

Lister ses notifications.

**Query params :**
- `unread_only` — 1 pour les non-lues uniquement
- `page`, `per_page`

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Livreur assigned",
      "body": "A delivery driver has been assigned to your order COL-2026-9DBA16",
      "type": "order_update",
      "data": { "order_id": 1 },
      "is_read": 0,
      "created_at": "2026-02-08 15:00:00"
    }
  ],
  "unread_count": 3,
  "meta": { "total": 10, "page": 1, "per_page": 20, "total_pages": 1 }
}
```

---

### `PUT /api/notifications/{id}/read` 🔒

Marquer une notification comme lue.

---

### `PUT /api/notifications/read-all` 🔒

Marquer toutes les notifications comme lues.

---

## 11. Évaluations (Ratings)

### `POST /api/orders/{order_id}/rating` 🔒 Client

Noter un livreur après une livraison.

**Body :**
```json
{
  "score": 5,
  "comment": "Excellent livreur, rapide et professionnel!"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `score` | int | ✅ | Note de 1 à 5 |
| `comment` | string | ❌ | Commentaire |

> La commande doit être en statut `delivered`. Un seul rating par commande par client.

---

### `GET /api/livreur/{livreur_id}/ratings` 🔒

Voir les évaluations d'un livreur.

---

## 12. Tarification

### `GET /api/pricing` 🟢 Public

Liste de toutes les règles de tarification actives.

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "city": "Douala",
      "base_price": 500,
      "price_per_km": 200,
      "min_price": 1000,
      "surge_multiplier": "1.00",
      "surcharge_moyen": 200,
      "surcharge_grand": 500,
      "weight_threshold_kg": "5.00",
      "price_per_extra_kg": 100,
      "night_multiplier": "1.50",
      "peak_multiplier": "1.25",
      "max_price": 0,
      "is_active": 1
    }
  ]
}
```

> **Formule de calcul du prix :**
> 1. `prix = base_price + (price_per_km × distance_km)`
> 2. `+ surcharge_moyen` ou `+ surcharge_grand` selon la taille du colis
> 3. `+ price_per_extra_kg × (poids - weight_threshold_kg)` si poids > seuil
> 4. `× surge_multiplier` (multiplicateur manuel)
> 5. `× night_multiplier` (22h-6h) ou `× peak_multiplier` (7h-9h, 17h-19h)
> 6. `= max(résultat, min_price)` — prix plancher
> 7. `= min(résultat, max_price)` — prix plafond (si max_price > 0)

---

### `GET /api/pricing/{city}` 🟢 Public

Tarification pour une ville spécifique.

---

### `POST /api/pricing/calculate` 🔒 Authentifié

Calculer le prix d'une livraison avant de créer la commande. Prend en compte la distance, l'heure, la taille/poids du colis et la valeur déclarée.

**Body :**
```json
{
  "pickup_lat": 4.0511,
  "pickup_lng": 9.7679,
  "delivery_lat": 4.0612,
  "delivery_lng": 9.7234,
  "package_weight_kg": 2.5,
  "package_value": 100000,
  "package_size": "moyen",
  "city": "Douala",
  "scheduled_time": "2026-02-16 22:00:00"
}
```

**Paramètres :**

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `pickup_lat` | float | ✅ | Latitude point de ramassage |
| `pickup_lng` | float | ✅ | Longitude point de ramassage |
| `delivery_lat` | float | ✅ | Latitude point de livraison |
| `delivery_lng` | float | ✅ | Longitude point de livraison |
| `package_weight_kg` | float | ❌ | Poids du colis (kg) |
| `package_value` | int | ❌ | Valeur déclarée (XAF) — surtaxe si > 10 000 XAF |
| `package_size` | string | ❌ | Taille : `petit`, `moyen`, `grand` |
| `city` | string | ❌ | Ville (défaut : `Douala`) |
| `scheduled_time` | datetime | ❌ | Heure prévue (format : `Y-m-d H:i:s`) |
| `maps_usage` | object | ❌ | Compteur d'appels Maps (ex: `{"autocomplete":3,"geocode":2,"directions":1}`) |

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "distance_km": 5.2,
    "base_price": 1000,
    "distance_fee": 2600,
    "night_fee": 500,
    "peak_fee": 0,
    "value_surcharge": 3000,
    "maps_api_cost": 80,
    "total_price": 7180,
    "min_price": 1000,
    "breakdown": {
      "base": 1000,
      "distance": 2600,
      "night": 500,
      "peak": 0,
      "value_insurance": 3000,
      "maps_api": 80
    },
    "currency": "XAF"
  }
}
```

**Détails du calcul :**

| Composant | Description |
|-----------|-------------|
| `base_price` | Prix de base selon la ville |
| `distance_fee` | Distance × prix/km |
| `night_fee` | Supplément nuit (22h-6h) |
| `peak_fee` | Supplément heure de pointe (7h-9h, 17h-19h) |
| `value_surcharge` | Assurance : 3% de la valeur si > 10 000 XAF (max 5 000 XAF) |
| `maps_api_cost` | Frais d'utilisation API Maps (basé sur `maps_usage`) |
| `total_price` | Somme de tous les composants (minimum garanti : `min_price`) |
| `min_price` | Prix minimum configuré pour la ville |

> **Important :** Le `total_price` retourné inclut **tous les frais** (livraison + assurance + Maps). C'est le montant exact que le client paiera lors de la création de la commande.

---

### `POST /api/pricing` 🔒 Admin

Créer une règle de tarification.

**Body :**
```json
{
  "city": "Yaoundé",
  "base_price": 600,
  "price_per_km": 250,
  "min_price": 1200,
  "surge_multiplier": 1.00,
  "surcharge_moyen": 300,
  "surcharge_grand": 600,
  "weight_threshold_kg": 5.00,
  "price_per_extra_kg": 150,
  "night_multiplier": 1.50,
  "peak_multiplier": 1.25,
  "max_price": 0
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `city` | string | ✅ | Ville |
| `base_price` | int | ✅ | Prix fixe de base (XAF) |
| `price_per_km` | int | ✅ | Prix par kilomètre |
| `min_price` | int | ✅ | Prix plancher |
| `surge_multiplier` | float | ❌ | Multiplicateur manuel (défaut : 1.00) |
| `surcharge_moyen` | int | ❌ | Supplément colis moyen (défaut : 0) |
| `surcharge_grand` | int | ❌ | Supplément colis grand (défaut : 0) |
| `weight_threshold_kg` | float | ❌ | Seuil de poids gratuit (défaut : 5 kg) |
| `price_per_extra_kg` | int | ❌ | Prix par kg au-delà du seuil (défaut : 100) |
| `night_multiplier` | float | ❌ | Multiplicateur tarif nuit (défaut : 1.50) |
| `peak_multiplier` | float | ❌ | Multiplicateur heures de pointe (défaut : 1.25) |
| `max_price` | int | ❌ | Prix plafond, 0 = illimité (défaut : 0) |

---

### `PUT /api/pricing/{id}` 🔒 Admin

Modifier une règle. Tous les champs ci-dessus sont modifiables + `is_active`.

---

## 13. Paramètres (Settings)

Table clé/valeur pour configurer le comportement de l'application sans modifier le code.

### `GET /api/settings/public` 🟢 Public

Paramètres non-sensibles pour l'app mobile.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "app_name": "ColiXpress",
    "app_version": "1.0.0",
    "default_currency": "XAF",
    "maintenance_mode": "0",
    "max_delivery_distance_km": "50",
    "default_search_radius_km": "5"
  }
}
```

---

### `GET /api/settings/maps-pricing` 🟢 Public

Tarifs d'utilisation de l'API Maps pour le tracking des coûts côté frontend.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "autocomplete": 10,
    "geocode": 15,
    "directions": 20,
    "place_details": 15,
    "currency": "XAF"
  }
}
```

> **Usage :** Le frontend utilise ces tarifs pour calculer le coût Maps en temps réel pendant la saisie de commande. Il envoie ensuite le compteur d'appels dans `maps_usage` lors de `POST /api/orders`.

---

### `GET /api/settings` 🔒 Admin

Tous les paramètres. Filtrable par catégorie.

**Query params :**
- `category` — Filtrer : `general`, `finance`, `delivery`, `pricing`, `security`, `limits`

**Réponse (200) :** Liste complète des paramètres avec `id`, `setting_key`, `setting_value`, `description`, `category`.

**Paramètres disponibles :**

| Clé | Catégorie | Description | Défaut |
|-----|-----------|-------------|--------|
| `commission_percent` | finance | Commission ColiXpress (%) | 10 |
| `developer_commission_percent` | finance | Commission commandes API (%) | 5 |
| `cancellation_fee` | finance | Frais annulation après acceptation (XAF) | 500 |
| `free_cancel_minutes` | finance | Délai annulation gratuite (minutes) | 5 |
| `max_delivery_distance_km` | delivery | Distance max de livraison (km) | 50 |
| `default_search_radius_km` | delivery | Rayon recherche livreur (km) | 5 |
| `max_search_radius_km` | delivery | Rayon recherche max (km) | 20 |
| `night_start_hour` | pricing | Début tarif nuit (0-23) | 22 |
| `night_end_hour` | pricing | Fin tarif nuit (0-23) | 6 |
| `peak_start_hour` | pricing | Début heure de pointe 1 (0-23) | 7 |
| `peak_end_hour` | pricing | Fin heure de pointe 1 (0-23) | 9 |
| `peak_start_hour_2` | pricing | Début heure de pointe 2 (0-23) | 17 |
| `peak_end_hour_2` | pricing | Fin heure de pointe 2 (0-23) | 19 |
| `package_value_threshold` | pricing | Seuil valeur pour surtaxe assurance (XAF) | 10000 |
| `package_value_surcharge_percent` | pricing | Pourcentage surtaxe valeur (%) | 3 |
| `package_value_max_surcharge` | pricing | Plafond surtaxe valeur (XAF) | 5000 |
| `maps_cost_autocomplete` | maps | Coût par recherche autocomplete (XAF) | 10 |
| `maps_cost_geocode` | maps | Coût par géocodage (XAF) | 15 |
| `maps_cost_directions` | maps | Coût par calcul d'itinéraire (XAF) | 20 |
| `maps_cost_place_details` | maps | Coût par détail de lieu (XAF) | 15 |
| `min_password_length` | security | Longueur min mot de passe | 6 |
| `token_expiry_hours` | security | Durée validité token (heures) | 720 |
| `otp_expiry_minutes` | security | Durée validité OTP (minutes) | 5 |
| `max_addresses_per_user` | limits | Max adresses par utilisateur | 10 |
| `app_name` | general | Nom de l'application | ColiXpress |
| `app_version` | general | Version | 1.0.0 |
| `default_currency` | general | Devise par défaut | XAF |
| `maintenance_mode` | general | Mode maintenance (0/1) | 0 |

---

### `GET /api/settings/categories` 🔒 Admin

Liste des catégories de paramètres.

---

### `PUT /api/settings/{key}` 🔒 Admin

Modifier un paramètre.

**Body :**
```json
{
  "value": "15"
}
```

---

### `PUT /api/settings/bulk` 🔒 Admin

Modifier plusieurs paramètres en une requête.

**Body :**
```json
{
  "settings": {
    "commission_percent": "12",
    "cancellation_fee": "300",
    "night_start_hour": "21"
  }
}
```

**Réponse (200) :**
```json
{
  "success": true,
  "message": "3 settings updated",
  "data": {
    "updated": ["commission_percent", "cancellation_fee", "night_start_hour"],
    "errors": []
  }
}
```

---

### `POST /api/settings` 🔒 Admin

Créer un nouveau paramètre.

**Body :**
```json
{
  "key": "sms_provider",
  "value": "twilio",
  "description": "Fournisseur SMS actif",
  "category": "general"
}
```

---

### `DELETE /api/settings/{key}` 🔒 Admin

Supprimer un paramètre.

---

## 14. Promotions

Gestion des codes promotionnels avec validation, limites d'utilisation et restrictions géographiques.

### `POST /api/promotions` 🔒 Admin

Créer un code promo.

**Body :**
```json
{
  "code": "BIENVENUE",
  "description": "Première livraison : réduction jusqu'à 2000 XAF",
  "discount_type": "percent",
  "discount_value": 100,
  "min_order_amount": 0,
  "max_discount": 2000,
  "max_uses": 1000,
  "max_uses_per_user": 1,
  "valid_from": null,
  "valid_until": "2026-12-31 23:59:59",
  "applicable_cities": "Douala,Yaoundé",
  "is_active": 1
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `code` | string | ✅ | Code promo (converti en majuscules) |
| `description` | string | ❌ | Description |
| `discount_type` | string | ✅ | `percent` ou `fixed` |
| `discount_value` | int | ✅ | Valeur (% ou montant fixe en XAF) |
| `min_order_amount` | int | ❌ | Montant minimum de commande (défaut : 0) |
| `max_discount` | int | ❌ | Plafond de réduction (défaut : 0 = illimité) |
| `max_uses` | int | ❌ | Nombre max d'utilisations total (0 = illimité) |
| `max_uses_per_user` | int | ❌ | Max par utilisateur (défaut : 1) |
| `valid_from` | datetime | ❌ | Date de début de validité |
| `valid_until` | datetime | ❌ | Date de fin de validité |
| `applicable_cities` | string | ❌ | Villes autorisées (séparées par virgule), null = toutes |
| `is_active` | int | ❌ | 1 = actif (défaut) |

---

### `GET /api/promotions` 🔒 Admin

Lister toutes les promotions (paginé).

---

### `GET /api/promotions/{id}` 🔒 Admin

Détail d'une promotion avec statistiques d'utilisation.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "code": "BIENVENUE",
    "discount_type": "percent",
    "discount_value": 100,
    "max_discount": 2000,
    "used_count": 42,
    "stats": {
      "total_uses": 42,
      "total_discount": 73500
    }
  }
}
```

---

### `PUT /api/promotions/{id}` 🔒 Admin

Modifier une promotion. Tous les champs sont modifiables.

---

### `DELETE /api/promotions/{id}` 🔒 Admin

Désactiver une promotion (soft delete).

---

### `POST /api/promotions/validate` 🔒 Auth requise

Valider un code promo avant de passer commande. Tout utilisateur authentifié peut valider.

**Body :**
```json
{
  "code": "BIENVENUE",
  "order_amount": 3500,
  "city": "Douala"
}
```

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "code": "BIENVENUE",
    "discount_type": "percent",
    "discount_value": 100,
    "discount": 2000,
    "final_amount": 1500
  }
}
```

**Erreurs possibles (422) :**
- Code promo invalide / inactif / expiré / épuisé
- Montant minimum requis
- Déjà utilisé par cet utilisateur
- Non valide pour cette ville

---

## 15. Bannières / Actualités

Système de bannières pour le slider de l'app mobile. Supporte le ciblage par rôle utilisateur et par ville, avec dates de validité et ordre d'affichage.

### `GET /api/banners` 🟢 Public (auth optionnelle)

Bannières actives pour le slider. Si l'utilisateur est authentifié (Bearer token), les bannières sont automatiquement filtrées par son rôle.

**Query params :**

| Param | Type | Requis | Description |
|-------|------|--------|-------------|
| `city` | string | ❌ | Ville pour le ciblage géographique |

**Réponse (200) :**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Bienvenue sur ColiXpress!",
      "description": "Livraison rapide partout au Cameroun...",
      "image_url": "/uploads/banners/welcome.jpg",
      "background_color": "#007bff",
      "link_url": null,
      "link_type": "internal",
      "link_data": { "screen": "promotions" }
    }
  ]
}
```

| Champ | Description |
|-------|-------------|
| `link_type` | `none` = pas de lien, `internal` = écran dans l'app, `external` = URL web |
| `link_data` | Données JSON pour les liens internes (ex: `{"screen": "promotions"}`) |

> **Ciblage :** sans auth → toutes les bannières. Avec auth → filtré par rôle. Le paramètre `city` filtre par ville.

---

### `GET /api/admin/banners` 🔒 Admin

Toutes les bannières (paginé, incluant les inactives).

---

### `POST /api/admin/banners` 🔒 Admin

Créer une bannière.

**Body :**
```json
{
  "title": "Promo du weekend",
  "description": "-30% sur toutes les livraisons ce weekend",
  "image_url": "/uploads/banners/promo.jpg",
  "link_url": null,
  "link_type": "internal",
  "link_data": { "screen": "promotions" },
  "target_roles": "client,shop_owner",
  "target_cities": "Douala,Yaoundé",
  "position": 0,
  "is_active": 1,
  "valid_from": "2026-02-15 00:00:00",
  "valid_until": "2026-02-17 23:59:59"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `title` | string | ✅ | Titre de la bannière |
| `description` | string | ❌ | Texte descriptif |
| `image_url` | string | ❌ | URL de l'image (ou utiliser l'upload) |
| `background_color` | string | ❌ | Code couleur (ex: `#007bff`) ou gradient CSS |
| `link_url` | string | ❌ | URL externe si `link_type=external` |
| `link_type` | string | ❌ | `none`, `internal`, `external` (défaut: `none`) |
| `link_data` | object | ❌ | Données JSON pour liens internes |
| `target_roles` | string | ❌ | Rôles ciblés séparés par virgule, null = tous |
| `target_cities` | string | ❌ | Villes ciblées séparées par virgule, null = toutes |
| `position` | int | ❌ | Ordre d'affichage (0 = premier) |
| `is_active` | int | ❌ | 1 = actif (défaut) |
| `valid_from` | datetime | ❌ | Date début validité |
| `valid_until` | datetime | ❌ | Date fin validité |

---

### `GET /api/admin/banners/{id}` 🔒 Admin

Détail d'une bannière.

---

### `PUT /api/admin/banners/{id}` 🔒 Admin

Modifier une bannière. Tous les champs sont modifiables.

---

### `DELETE /api/admin/banners/{id}` 🔒 Admin

Supprimer une bannière.

---

### `PUT /api/admin/banners/reorder` 🔒 Admin

Réordonner les bannières du slider.

**Body :**
```json
{
  "ids": [3, 1, 2, 5]
}
```

---

### `POST /api/admin/banners/{id}/upload` 🔒 Admin

Uploader une image pour une bannière.

**Body :** `multipart/form-data` avec champ `image`

**Contraintes :** jpg, png, webp, gif — max 5 MB

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Image uploaded",
  "data": { "image_url": "/uploads/banners/banner_1_1708012345.jpg" }
}
```

---

## 16. Maps — Proxy Cache Google Maps

Proxy serveur pour les appels Google Maps avec cache automatique en base de données. Réduit drastiquement la consommation de l'API Google Maps.

> **Principe** : L'app mobile appelle votre API au lieu de Google directement. Le serveur met en cache les résultats et les réutilise pour les requêtes identiques.

### `GET /api/maps/autocomplete` 🔒 Authentifié

Suggestions d'adresses (autocomplete).

**Query params :**
- `input` *(requis)* — Texte de recherche (min 2 caractères)
- `country` — Code ISO pays (ex: `CM`)
- `location` — Position GPS de l'utilisateur (ex: `4.0511,9.7679`). Active le tri par proximité et le calcul de `distance_meters`
- `radius` — Rayon de recherche en mètres (défaut: `50000`)

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "predictions": [
      {
        "place_id": "ChIJ...",
        "description": "Bonapriso, Douala, Cameroun",
        "main_text": "Bonapriso",
        "secondary_text": "Douala, Cameroun",
        "types": ["geocode", "neighborhood", "political"],
        "matched_substrings": [{ "length": 9, "offset": 0 }],
        "distance_meters": 8051
      }
    ],
    "source": "cache"
  }
}
```

**Champs de la réponse :**

| Champ | Description |
|-------|-------------|
| `place_id` | Identifiant Google (pour appeler `/api/maps/place-details`) |
| `description` | Adresse complète |
| `main_text` | Nom principal du lieu |
| `secondary_text` | Contexte (ville, pays) |
| `types` | Types de lieu (`geocode`, `establishment`, `restaurant`, etc.) |
| `matched_substrings` | Positions des lettres correspondantes (pour le bold dans l'UI) |
| `distance_meters` | Distance depuis `location` en mètres (présent uniquement si `location` est fourni) |

> Le champ `source` indique `"google"` (appel réel) ou `"cache"` (résultat mis en cache).

---

### `GET /api/maps/geocode` 🔒 Authentifié

Convertir une adresse en coordonnées GPS.

**Query params :**
- `address` *(requis)* — Adresse à géocoder
- `country` — Code ISO pays

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "location": {
      "formatted_address": "Akwa, Douala, Cameroun",
      "latitude": 4.0510564,
      "longitude": 9.7678687,
      "place_id": "ChIJ...",
      "types": ["sublocality"]
    },
    "source": "cache"
  }
}
```

---

### `GET /api/maps/reverse-geocode` 🔒 Authentifié

Convertir des coordonnées GPS en adresse.

**Query params :**
- `lat` *(requis)* — Latitude
- `lng` *(requis)* — Longitude

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "address": {
      "formatted_address": "Rue de la Joie, Akwa, Douala, Cameroun",
      "place_id": "ChIJ...",
      "types": ["street_address"],
      "components": {
        "street": "Rue de la Joie",
        "quarter": "Akwa",
        "city": "Douala",
        "region": "Littoral",
        "country": "Cameroun"
      }
    },
    "source": "google"
  }
}
```

---

### `GET /api/maps/directions` 🔒 Authentifié

Calculer un itinéraire entre deux points.

**Query params :**
- `origin_lat` *(requis)* — Latitude départ
- `origin_lng` *(requis)* — Longitude départ
- `dest_lat` *(requis)* — Latitude arrivée
- `dest_lng` *(requis)* — Longitude arrivée

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "route": {
      "distance_meters": 5200,
      "distance_text": "5,2 km",
      "duration_seconds": 900,
      "duration_text": "15 min",
      "start_address": "Akwa, Douala",
      "end_address": "Bonapriso, Douala",
      "polyline": "encoded_polyline_string..."
    },
    "source": "cache"
  }
}
```

---

### `GET /api/maps/place-details` 🔒 Authentifié

Obtenir les détails d'un lieu par son `place_id` (retourné par l'autocomplete).

**Query params :**
- `place_id` *(requis)* — Identifiant Google Place

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "place": {
      "name": "Akwa Palace",
      "formatted_address": "Boulevard de la Liberté, Akwa, Douala",
      "latitude": 4.0510564,
      "longitude": 9.7678687,
      "place_id": "ChIJ...",
      "components": {
        "street": "Boulevard de la Liberté",
        "quarter": "Akwa",
        "city": "Douala",
        "region": "Littoral",
        "country": "Cameroun"
      }
    },
    "source": "google"
  }
}
```

---

### `GET /api/admin/maps/cache-stats` 🔒 Admin

Statistiques du cache Google Maps.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "by_type": [
      { "cache_type": "autocomplete", "total_entries": 150, "total_hits": 1200, "active_entries": 140, "expired_entries": 10 },
      { "cache_type": "geocode", "total_entries": 80, "total_hits": 500, "active_entries": 80, "expired_entries": 0 }
    ],
    "total_entries": 230,
    "total_hits": 1700
  }
}
```

---

### `POST /api/admin/maps/cache-purge` 🔒 Admin

Supprimer les entrées de cache expirées.

**Réponse (200) :**
```json
{
  "success": true,
  "message": "Cache purgé: 15 entrées expirées supprimées",
  "data": { "purged_entries": 15 }
}
```

### Durées de cache par type

| Type | Durée | Raison |
|------|-------|--------|
| `autocomplete` | 30 jours | Les suggestions changent rarement |
| `geocode` | 90 jours | Les adresses sont stables |
| `reverse_geocode` | 90 jours | Les coordonnées sont stables |
| `directions` | 3 jours | Le trafic change |
| `distance` | 3 jours | Le trafic change |

---

## 17. API Développeur

Permet à des plateformes tierces d'intégrer ColiXpress dans leurs applications (e-commerce, marketplace, etc.).

### Architecture

- **Gestion des clés API** → Authentification par Bearer token (`/api/developer/*`)
- **Endpoints externes** → Authentification par clé API (`/api/v1/*`)

### Authentification API Key

Les endpoints `/api/v1/*` utilisent des headers spécifiques :

```
X-Api-Key: <votre_api_key>
X-Api-Secret: <votre_api_secret>
```

> Les clés sont générées via le dashboard développeur. Le `api_secret` n'est affiché qu'une seule fois à la création.

### Rôles

Un utilisateur doit avoir le rôle `developer` pour gérer des clés API. Contactez un administrateur pour obtenir ce rôle.

---

### Gestion des clés API (Bearer token)

#### `POST /api/developer/api-keys` 🔒 Developer

Créer une clé API.

**Body :**
```json
{
  "name": "Mon App E-commerce",
  "webhook_url": "https://monapp.com/webhooks/colixpress",
  "allowed_ips": "1.2.3.4,5.6.7.8"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `name` | string | ✅ | Nom de l'application |
| `webhook_url` | string | ❌ | URL de callback pour les mises à jour |
| `allowed_ips` | string | ❌ | IPs autorisées (virgule), null = toutes |

**Réponse (201) :**
```json
{
  "success": true,
  "message": "API key created",
  "data": {
    "id": 1,
    "api_key": "eace957d8df3...64ba146",
    "api_secret": "cd77dbdfa547...1533c8",
    "message": "Save your api_secret now. It will not be shown again."
  }
}
```

---

#### `GET /api/developer/api-keys` 🔒 Developer

Lister ses clés API (sans le secret).

---

#### `PUT /api/developer/api-keys/{id}` 🔒 Developer

Modifier une clé API.

**Champs modifiables :** `name`, `webhook_url`, `allowed_ips`, `is_active`, `is_test_mode`

---

#### `POST /api/developer/api-keys/{id}/regenerate-secret` 🔒 Developer

Regénérer le secret d'une clé API.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "api_secret": "nouveau_secret...",
    "message": "Save your new api_secret now. It will not be shown again."
  }
}
```

---

#### `DELETE /api/developer/api-keys/{id}` 🔒 Developer

Désactiver une clé API.

---

#### `GET /api/developer/api-keys/{id}/stats` 🔒 Developer

Statistiques d'utilisation de la clé API.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "total_orders": 156,
    "total_revenue": 450000,
    "avg_price": 2884,
    "total_distance": 312.5,
    "orders_by_status": [
      { "status": "delivered", "count": 120 },
      { "status": "pending", "count": 15 },
      { "status": "in_transit", "count": 8 }
    ]
  }
}
```

---

### Endpoints externes (API Key)

> Tous les endpoints ci-dessous nécessitent les headers `X-Api-Key` + `X-Api-Secret`.

#### `POST /api/v1/orders` 🔑

Créer une commande via API.

**Body :**
```json
{
  "external_reference": "MYAPP-ORD-001",
  "pickup_address": "Akwa, Douala",
  "pickup_lat": 4.0511,
  "pickup_lng": 9.7679,
  "pickup_contact_name": "Entrepôt Central",
  "pickup_contact_phone": "+237691000000",
  "dropoff_address": "Bonaberi, Douala",
  "dropoff_lat": 4.0611,
  "dropoff_lng": 9.7879,
  "dropoff_contact_name": "Client Final",
  "dropoff_contact_phone": "+237699888777",
  "package_description": "Colis e-commerce",
  "package_size": "petit",
  "payment_method": "cash"
}
```

| Champ | Type | Requis | Description |
|-------|------|--------|-------------|
| `external_reference` | string | ❌ | Votre référence interne (pour suivi) |
| `order_type` | string | ❌ | `direct` (défaut) ou `shop` |
| `pickup_address` | string | ✅ direct | Adresse d'enlèvement |
| `pickup_lat` / `pickup_lng` | float | ❌ | Coordonnées GPS enlèvement |
| `pickup_contact_name` | string | ❌ | Nom contact enlèvement |
| `pickup_contact_phone` | string | ❌ | Téléphone contact enlèvement |
| `dropoff_address` | string | ✅ | Adresse de livraison |
| `dropoff_lat` / `dropoff_lng` | float | ❌ | Coordonnées GPS livraison |
| `dropoff_contact_name` | string | ❌ | Nom destinataire |
| `dropoff_contact_phone` | string | ❌ | Téléphone destinataire |
| `package_description` | string | ❌ | Description du colis |
| `package_size` | string | ❌ | `petit`, `moyen`, `grand` |
| `package_weight_kg` | float | ❌ | Poids en kg |
| `payment_method` | string | ❌ | `cash`, `mobile_money` |
| `notes` | string | ❌ | Instructions |
| `scheduled_at` | datetime | ❌ | Livraison programmée |
| `shop_id` | int | ✅ shop | ID boutique (si type shop) |
| `items` | array | ❌ shop | Articles `[{shop_item_id, quantity}]` |

> Le prix est automatiquement calculé par ColiXpress et retourné dans la réponse.

---

#### `GET /api/v1/orders` 🔑

Lister les commandes créées avec cette clé API.

**Query params :** `status`, `page`, `per_page`

---

#### `GET /api/v1/orders/{id}` 🔑

Détail d'une commande (inclut historique de statut et articles).

---

#### `GET /api/v1/orders/by-reference/{reference}` 🔑

Retrouver une commande par votre `external_reference`.

---

#### `PUT /api/v1/orders/{id}/cancel` 🔑

Annuler une commande.

**Body :**
```json
{
  "cancellation_reason": "Client a changé d'avis"
}
```

> Annulation possible uniquement si statut = `pending` ou `accepted`.

---

#### `GET /api/v1/orders/{id}/tracking` 🔑

Suivre la position GPS du livreur.

**Réponse (200) :**
```json
{
  "success": true,
  "data": {
    "order_status": "in_transit",
    "current_position": {
      "latitude": "4.05110000",
      "longitude": "9.76790000",
      "updated_at": "2026-02-08 15:30:00"
    },
    "trail": [...]
  }
}
```

---

#### `GET /api/v1/estimate` 🔑

Estimer le prix d'une livraison.

**Query params :** `pickup_lat`, `pickup_lng`, `dropoff_lat`, `dropoff_lng`, `city`

---

#### `GET /api/v1/shops` 🔑

Parcourir les boutiques. Query : `category_id`, `city`, `page`, `per_page`.

---

#### `GET /api/v1/shops/{id}` 🔑

Détail d'une boutique avec ses articles.

---

#### `GET /api/v1/countries` 🔑

Liste des pays supportés.

---

#### `GET /api/v1/pricing` 🔑

Règles de tarification actives.

---

## Résumé des endpoints

### API Mobile / Web (Bearer Token)

| # | Méthode | Endpoint | Auth | Rôle |
|---|---------|----------|------|------|
| 1 | GET | `/api/health` | 🟢 | — |
| 2 | GET | `/api/countries` | 🟢 | — |
| 3 | POST | `/api/auth/send-otp` | 🟢 | — |
| 4 | POST | `/api/auth/verify-otp` | 🟢 | — |
| 5 | POST | `/api/auth/register` | 🟢 | — |
| 6 | POST | `/api/auth/login` | 🟢 | — |
| 7 | POST | `/api/auth/logout` | 🔒 | — |
| 8 | GET | `/api/auth/me` | 🔒 | — |
| 9 | PUT | `/api/auth/password` | 🔒 | — |
| 10 | GET | `/api/user/profile` | 🔒 | — |
| 11 | PUT | `/api/user/profile` | 🔒 | — |
| 12 | POST | `/api/user/profile-photo` | 🔒 | — |
| 13 | DELETE | `/api/user/account` | 🔒 | — |
| 14 | GET | `/api/addresses` | 🔒 | — |
| 15 | POST | `/api/addresses` | 🔒 | — |
| 16 | GET | `/api/addresses/{id}` | 🔒 | — |
| 17 | PUT | `/api/addresses/{id}` | 🔒 | — |
| 18 | DELETE | `/api/addresses/{id}` | 🔒 | — |
| 19 | GET | `/api/orders` | 🔒 | — |
| 20 | POST | `/api/orders` | 🔒 | — |
| 21 | GET | `/api/orders/pending` | 🔒 | livreur/admin |
| 22 | GET | `/api/orders/estimate` | 🔒 | — |
| 23 | GET | `/api/orders/frequent-places` | 🔒 | — |
| 24 | GET | `/api/orders/frequent-shops` | 🔒 | — |
| 25 | GET | `/api/orders/{id}` | 🔒 | — |
| 26 | PUT | `/api/orders/{id}/accept` | 🔒 | livreur |
| 27 | PUT | `/api/orders/{id}/status` | 🔒 | livreur |
| 28 | PUT | `/api/orders/{id}/cancel` | 🔒 | client |
| 29 | GET | `/api/orders/{id}/tracking` | 🔒 | — |
| 30 | POST | `/api/orders/{order_id}/rating` | 🔒 | client |
| 31 | GET | `/api/shops` | 🟢 | — |
| 32 | GET | `/api/shops/popular` | 🟢 | — |
| 33 | GET | `/api/shops/{id}` | 🟢 | — |
| 34 | GET | `/api/shop-categories` | 🟢 | — |
| 35 | GET | `/api/shops/{shop_id}/items` | 🟢 | — |
| 34 | POST | `/api/shops` | 🔒 | shop_owner/admin |
| 35 | PUT | `/api/shops/{id}` | 🔒 | owner/admin |
| 36 | GET | `/api/shops/my` | 🔒 | shop_owner |
| 37 | PUT | `/api/shops/{id}/approve` | 🔒 | admin |
| 38 | POST | `/api/shops/{shop_id}/items` | 🔒 | owner/admin |
| 39 | PUT | `/api/shops/{shop_id}/items/{id}` | 🔒 | owner/admin |
| 40 | DELETE | `/api/shops/{shop_id}/items/{id}` | 🔒 | owner/admin |
| 41 | POST | `/api/livreur/register` | 🔒 | — |
| 42 | GET | `/api/livreur/profile` | 🔒 | livreur |
| 43 | PUT | `/api/livreur/profile` | 🔒 | livreur |
| 44 | PUT | `/api/livreur/availability` | 🔒 | livreur |
| 45 | POST | `/api/livreur/location` | 🔒 | livreur |
| 46 | GET | `/api/livreur/nearby` | 🔒 | — |
| 47 | PUT | `/api/livreur/{id}/approve` | 🔒 | admin |
| 48 | GET | `/api/livreur/{livreur_id}/ratings` | 🔒 | — |
| 49 | GET | `/api/notifications` | 🔒 | — |
| 50 | PUT | `/api/notifications/{id}/read` | 🔒 | — |
| 51 | PUT | `/api/notifications/read-all` | 🔒 | — |
| 52 | GET | `/api/pricing` | 🟢 | — |
| 53 | GET | `/api/pricing/{city}` | 🟢 | — |
| 54 | POST | `/api/pricing/calculate` | 🔒 | — |
| 55 | POST | `/api/pricing` | 🔒 | admin |
| 56 | PUT | `/api/pricing/{id}` | 🔒 | admin |
| 57 | GET | `/api/settings/public` | 🟢 | — |
| 58 | GET | `/api/settings/maps-pricing` | 🟢 | — |
| 59 | GET | `/api/settings` | 🔒 | admin |
| 60 | GET | `/api/settings/categories` | 🔒 | admin |
| 61 | POST | `/api/settings` | 🔒 | admin |
| 62 | PUT | `/api/settings/bulk` | 🔒 | admin |
| 63 | PUT | `/api/settings/{key}` | 🔒 | admin |
| 64 | DELETE | `/api/settings/{key}` | 🔒 | admin |
| 65 | GET | `/api/promotions` | 🔒 | admin |
| 66 | POST | `/api/promotions` | 🔒 | admin |
| 67 | POST | `/api/promotions/validate` | 🔒 | — |
| 68 | GET | `/api/promotions/{id}` | 🔒 | admin |
| 69 | PUT | `/api/promotions/{id}` | 🔒 | admin |
| 70 | DELETE | `/api/promotions/{id}` | 🔒 | admin |
| 71 | GET | `/api/banners` | 🟢* | — |
| 72 | GET | `/api/admin/banners` | 🔒 | admin |
| 73 | POST | `/api/admin/banners` | 🔒 | admin |
| 74 | PUT | `/api/admin/banners/reorder` | 🔒 | admin |
| 75 | GET | `/api/admin/banners/{id}` | 🔒 | admin |
| 76 | PUT | `/api/admin/banners/{id}` | 🔒 | admin |
| 77 | DELETE | `/api/admin/banners/{id}` | 🔒 | admin |
| 78 | POST | `/api/admin/banners/{id}/upload` | 🔒 | admin |
| 79 | GET | `/api/maps/autocomplete` | 🔒 | — |
| 80 | GET | `/api/maps/geocode` | 🔒 | — |
| 81 | GET | `/api/maps/reverse-geocode` | 🔒 | — |
| 82 | GET | `/api/maps/directions` | 🔒 | — |
| 83 | GET | `/api/maps/place-details` | 🔒 | — |
| 84 | GET | `/api/admin/maps/cache-stats` | 🔒 | admin |
| 85 | POST | `/api/admin/maps/cache-purge` | 🔒 | admin |
| 86 | GET | `/api/developer/api-keys` | 🔒 | developer |
| 87 | POST | `/api/developer/api-keys` | 🔒 | developer |
| 88 | PUT | `/api/developer/api-keys/{id}` | 🔒 | developer |
| 89 | POST | `/api/developer/api-keys/{id}/regenerate-secret` | 🔒 | developer |
| 90 | DELETE | `/api/developer/api-keys/{id}` | 🔒 | developer |
| 91 | GET | `/api/developer/api-keys/{id}/stats` | 🔒 | developer |

> 🟢* = public avec auth optionnelle (si Bearer token présent, filtre par rôle)

### API Développeur (API Key : `X-Api-Key` + `X-Api-Secret`)

| # | Méthode | Endpoint | Description |
|---|---------|----------|-------------|
| 92 | POST | `/api/v1/orders` | Créer une commande |
| 93 | GET | `/api/v1/orders` | Lister ses commandes |
| 94 | GET | `/api/v1/orders/{id}` | Détail commande |
| 95 | GET | `/api/v1/orders/by-reference/{ref}` | Chercher par ref externe |
| 96 | PUT | `/api/v1/orders/{id}/cancel` | Annuler |
| 97 | GET | `/api/v1/orders/{id}/tracking` | Tracking GPS |
| 98 | GET | `/api/v1/estimate` | Estimation prix |
| 99 | GET | `/api/v1/shops` | Liste boutiques |
| 100 | GET | `/api/v1/shops/{id}` | Détail boutique |
| 101 | GET | `/api/v1/countries` | Pays |
| 102 | GET | `/api/v1/pricing` | Tarification |
