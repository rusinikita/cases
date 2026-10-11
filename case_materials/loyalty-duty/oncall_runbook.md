# Ранбук дежурного: команда лояльности

Дежурство недельное, с понедельника по воскресенье. Канал #loyalty-duty, обращения регистрирует duty-bot. Ниже то, что дежурный делает руками.

## 1. «Почему не применился промокод»

Админка показывает клиенту только «недоступен», причину приходится доставать из базы. Доступ: read-реплика `loyalty-ro`.

```sql
-- что за код и какие у кампании ограничения
SELECT p.id, p.code, p.is_active, p.starts_at, p.ends_at, p.max_activations, p.per_user_limit,
       c.name, c.segment, c.city_ids, c.time_from, c.time_to, c.min_ride_amount
FROM promo_codes p
JOIN campaigns c ON c.id = p.campaign_id
WHERE p.code = :code;

-- что с этим кодом делал пользователь
SELECT promo_id, activated_at, used_at, ride_id
FROM promo_activations
WHERE user_id = :user_id
ORDER BY activated_at DESC
LIMIT 20;

-- сколько всего активаций у кода
SELECT count(*) FROM promo_activations WHERE promo_id = :promo_id;

-- месячный лимит пользователя
SELECT count(*) FROM promo_activations
WHERE user_id = :user_id AND used_at >= date_trunc('month', now());
```

Дальше сверить по порядку: код существует → кампания активна и не истекла → сегмент → город → время суток → минимальная сумма → лимит на пользователя → лимит кампании → месячный лимит пользователя. Первое несовпадение и есть причина.

## 2. Пачка промокодов для маркетинга или партнёра

Нужен доступ в прод-кластер и роль `loyalty_admin`.

```
kubectl -n loyalty exec deploy/promo-tools -- \
  python tools/gen_promo_batch.py --campaign <slug> --count <N> --amount <RUB> --expires <YYYY-MM-DD> --prefix <PREFIX>
```

Скрипт создаёт кампанию, если её нет, и кладёт CSV в `/tmp/batches/`. Забрать файл через `kubectl cp` и отправить заказчику в тред. Именные коды и скидку в процентах скрипт не умеет: такие пачки правятся руками в CSV и загружаются через `tools/import_promo_csv.py`.

## 3. Перенос бонусов между аккаунтами

```
kubectl -n loyalty exec deploy/promo-tools -- \
  python tools/move_bonuses.py --from <user_id> --to <user_id> --ticket <номер обращения>
```

Перед запуском проверить, что оба аккаунта существуют, не заблокированы антифродом и что личность подтверждена в обращении. Скрипт переносит весь баланс одной транзакцией и пишет запись в `bonus_transfers`. Суммы больше 2 000 бонусов дополнительно просмотреть глазами по истории начислений.

## 4. Компенсация бонусами

Админка → Пользователь → Бонусы → «Начислить». Указать сумму, причину и номер обращения. Операция доступна роли `loyalty_operator` и выше, лимит 1 000 бонусов за операцию.

## 5. «Не начислились бонусы за поездку»

```sql
SELECT * FROM bonus_accruals WHERE ride_id = :ride_id;
```

Если записи нет, проверить, что поездка оплачена, не отменена и не корпоративная (Админка → Поездка). Если всё так, начислить вручную 5% от оплаченной суммы, как в п. 4, с причиной «доначисление».

## 6. Изменение параметров кампании

Админка → Лояльность → Кампании → «Редактировать»: срок, лимит активаций, города. Роль `loyalty_admin`. Изменение срока персонального кода пользователя в админке не предусмотрено, делается апдейтом в основной базе:

```sql
UPDATE promo_activations SET expires_at = :new_date WHERE promo_id = :promo_id AND user_id = :user_id;
```

## 7. Выгрузка для финансов

Раз в месяц, по запросу финансов.

```sql
SELECT date_trunc('day', created_at) AS day,
       sum(amount) FILTER (WHERE kind = 'accrual') AS accrued,
       sum(amount) FILTER (WHERE kind = 'spend')   AS spent
FROM bonus_ledger
WHERE created_at >= :month_start AND created_at < :month_end
GROUP BY 1
ORDER BY 1;
```

## Константы, о которых часто спрашивают

| Параметр | Значение | Где |
|---|---|---|
| Промокодов на поездку | 1 | `promo/limits.go` |
| Промокодов в месяц на пользователя | 2 | `promo/limits.go`, `MaxPromoPerMonth` |
| Начисление бонусов | 5% от оплаченной суммы | `bonus/accrual.go` |
| Максимальная доля оплаты бонусами | 30% | `bonus/spend.go`, `MaxBonusShare` |
| Срок жизни бонусов | 12 месяцев | `bonus/expire.go` |
