-- name: GetPromoByCode :one
SELECT id, code, discount_percent, starts_at, ends_at, is_active,
       max_activations, per_user_limit, segment, city_ids
FROM promo_codes
WHERE code = $1;

-- name: CountUserActivations :one
SELECT count(*)
FROM promo_activations
WHERE promo_id = $1
  AND user_id = $2;

-- name: CountActivations :one
SELECT count(*)
FROM promo_activations
WHERE promo_id = $1;

-- name: CreateActivation :exec
INSERT INTO promo_activations (promo_id, user_id, source, activated_at)
VALUES ($1, $2, $3, $4);

-- name: ListUserActivations :many
SELECT a.promo_id, p.code, p.discount_percent, a.source, a.activated_at, a.order_id
FROM promo_activations a
JOIN promo_codes p ON p.id = a.promo_id
WHERE a.user_id = $1
ORDER BY a.activated_at DESC
LIMIT $2;
