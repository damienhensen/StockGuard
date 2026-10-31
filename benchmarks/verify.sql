SELECT
    p.id,
    p.initial_stock,
    p.available_quantity,
    COUNT(r.id) AS reservation_count,
    COALESCE(SUM(r.quantity), 0) AS reserved_quantity,
    p.available_quantity >= 0
    AND p.available_quantity + COALESCE(SUM(r.quantity), 0) = p.initial_stock AS invariant_ok
FROM
    products p
    LEFT JOIN reservations r ON r.product_id = p.id
WHERE
    p.sku = 'GPU-RTX-001'
GROUP BY
    p.id;