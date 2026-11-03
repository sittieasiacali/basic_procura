<?php

$deadline = time() + 120;
do {
    try {
        new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (PDOException) {
        sleep(2);
    }
} while (time() < $deadline);
fwrite(STDERR, "Database did not become available within 120 seconds.\n");
exit(1);
