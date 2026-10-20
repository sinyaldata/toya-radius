<?php

return [

    /*
    |--------------------------------------------------------------------
    | Domain Pusat
    |--------------------------------------------------------------------
    | Domain/host yang TIDAK dianggap subdomain tenant — akses lewat
    | domain ini pakai database utama (.env DB_DATABASE) apa adanya,
    | sama seperti sebelum fitur multi-tenant ini ada.
    |
    | Isi via .env CENTRAL_DOMAINS, pisah pakai koma kalau lebih dari 1,
    | mis: CENTRAL_DOMAINS=sinyaldata.web.id,103.180.165.245,localhost
    */
    'central_domains' => array_filter(array_map('trim', explode(
        ',',
        env('CENTRAL_DOMAINS', 'sinyaldata.web.id,103.180.165.245,localhost')
    ))),

];
