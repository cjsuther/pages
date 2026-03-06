<?php
putenv("OPENSSL_CONF=C:\\php\\extras\\ssl\\openssl.cnf");

echo OPENSSL_VERSION_TEXT . PHP_EOL;

$config = [
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
];

$res = openssl_pkey_new($config);

if ($res === false) {
    echo "FAIL\n";
    while ($msg = openssl_error_string()) {
        echo $msg . PHP_EOL;
    }
} else {
    echo "OK (EC key generated)\n";
}
