SELECT meta_key, meta_value
FROM wp_5bifyj_postmeta
WHERE post_id = 139
AND meta_key IN ('finance_data_from', 'finance_data_to', 'external_mp_id', 'total_spend')
ORDER BY meta_key;
