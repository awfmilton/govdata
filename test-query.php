<?php
try {
    $p = new PDO('mysql:host=35.230.186.163;port=3306;dbname=grantconts', 'pt_read', '123456789!!!!!!!!!', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    
    $sql = 'SELECT `ref_number`, `agreement_number`, `agreement_value`, `agreement_start_date`, `year`, `fiscal_year`, `agreement_title_en`, `description_eng`, `prog_name_en`, `prog_purpose_en`, `owner_org_en`, `recipient_legal_name`, `recipient_operating_name`, `recipient_city`, `recipient_province` FROM `master_table` WHERE `recipient_legal_name` LIKE ? LIMIT 1';
    
    $stmt = $p->prepare($sql);
    $stmt->execute(['%Egale Canada%']);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    echo "Query OK. Rows: " . count($rows) . "\n";
} catch (Exception $e) {
    echo "Query failed: " . $e->getMessage() . "\n";
}
