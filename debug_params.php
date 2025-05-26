<?php
// debug_params.php
$query = 'is_pos=0&tableid=1041&recordid=&lets_addNI=0&…&ds-partnumber_1=INDIAN+-+JUTE+1.8m&…&qty_1=1&…';
parse_str($query, $params);
echo "<pre>";
print_r($params);
