<?php
/**
 * std_programs.php — standard safari/trekking program templates.
 *
 * Ported 1:1 from the SavannahScripts cp*.bat files that the old Java BackOffice
 * "Copy Programs" panel used. Each program copies template file(s) from the
 * Dropbox /itineraries tree into a request's folder, renamed
 *   {ProgNumber}_{FolderName}_{dst}
 *
 * Structure: group label => [ program label => [ ['src'=>…, 'dst'=>…], … ] ].
 *   src : full Dropbox path of the template file. A src beginning with '{YEAR}/'
 *         is resolved against the request's own year folder at copy time.
 *   dst : destination filename suffix (what follows {ProgNumber}_{FolderName}_).
 *
 * Program labels are unique across all groups (the copy handler flattens by label).
 * Keep this file in sync with the cp*.bat scripts if the templates change.
 */

$IT = '/itineraries/SafariClassic/it/';
$BE = '/itineraries/Beach/';
$KI = '/itineraries/Kili/it/Trekking/';

return [
    'Safari' => [
        'Duma'              => [['src'=>$IT.'SavannahExplorers_DumaSafari.docx','dst'=>'DumaSafari.docx'],   ['src'=>$IT.'Duma_Calc.xlsx','dst'=>'Duma_Calc.xlsx']],
        'DumaShort'         => [['src'=>$IT.'SavannahExplorers_DumaShortSafari.docx','dst'=>'DumaShortSafari.docx'], ['src'=>$IT.'DumaShort_Calc.xlsx','dst'=>'DumaShort_Calc.xlsx']],
        'Pumba'             => [['src'=>$IT.'SavannahExplorers_PumbaSafari.docx','dst'=>'PumbaSafari.docx'], ['src'=>$IT.'Pumba_Calc.xlsx','dst'=>'Pumba_Calc.xlsx']],
        'Simba'             => [['src'=>$IT.'SavannahExplorers_SimbaSafari.docx','dst'=>'SimbaSafari.docx'], ['src'=>$IT.'Simba_Calc.xlsx','dst'=>'Simba_Calc.xlsx']],
        'Nyumbu'            => [['src'=>$IT.'SavannahExplorers_NyumbuSafari.docx','dst'=>'NyumbuSafari.docx'], ['src'=>$IT.'Nyumbu_Calc.xlsx','dst'=>'Nyumbu_Calc.xlsx']],
        'Kiboko'            => [['src'=>$IT.'SavannahExplorers_KibokoSafari.docx','dst'=>'KibokoSafari.docx'], ['src'=>$IT.'KibokoSafari_Calc.xlsx','dst'=>'Kiboko_Calc.xlsx']],
        'Tembo'             => [['src'=>$IT.'SavannahExplorers_TemboSafari.docx','dst'=>'TemboSafari.docx'], ['src'=>$IT.'Tembo_Calc.xlsx','dst'=>'Tembo_Calc.xlsx']],
        'Nyani'             => [['src'=>$IT.'SavannahExplorers_NyaniSafari.docx','dst'=>'NyaniSafari.docx'], ['src'=>$IT.'Nyani_Calc.xlsx','dst'=>'Nyani_Calc.xlsx']],
        'Chui'              => [['src'=>$IT.'SavannahExplorers_ChuiSafari.docx','dst'=>'ChuiSafari.docx'], ['src'=>$IT.'ChuiSafari_Calc.xlsx','dst'=>'ChuiSafari_Calc.xlsx']],
        'Faru'              => [['src'=>$IT.'SavannahExplorers_FaruSafari.docx','dst'=>'FaruSafari.docx'], ['src'=>$IT.'FaruSafari_Calc.xlsx','dst'=>'Faru_Calc.xlsx']],
        'Mbogo'             => [['src'=>$IT.'SavannahExplorers_MbogoSafari.docx','dst'=>'MbogoSafari.docx'], ['src'=>$IT.'MbogoSafari_Calc.xlsx','dst'=>'Mbogo_Calc.xlsx']],
        'GranSafari'        => [['src'=>$IT.'SavannahExplorers_GranSafariTanzania.docx','dst'=>'GranSafariTanzania.docx'], ['src'=>$IT.'GranSafariTanzania_Calc.xlsx','dst'=>'GranSafariTanzania_Calc.xlsx']],
        'MigrationWinter'   => [['src'=>$IT.'SavannahExplorers_SafariGrandeMigrazioneInverno.docx','dst'=>'SafariGrandeMigrazione.docx'], ['src'=>$IT.'MigrazioneInverno_Calc.xlsx','dst'=>'MigrazioneInverno_Calc.xlsx']],
        'MigrationSummer'   => [['src'=>$IT.'SavannahExplorers_SafariGrandeMigrazioneEstate.docx','dst'=>'SafariGrandeMigrazioneEstate.docx'], ['src'=>$IT.'MigrazioneEstate_Calc.xlsx','dst'=>'MigrazioneEstate_Calc.xlsx']],
        'Ndege'             => [['src'=>$IT.'SavannahExplorers_NdegeSafari.docx','dst'=>'NdegeSafari.docx'], ['src'=>$IT.'Ndege_Calc.xlsx','dst'=>'Ndege_Calc.xlsx']],
        'Baobab'            => [['src'=>$IT.'Baobab.docx','dst'=>'Baobab.docx'], ['src'=>$IT.'BaobabDeluxe2025_Calc.xlsx','dst'=>'BaobabDeluxe2025_Calc.xlsx']],
        'Duma-GRP'          => [['src'=>'{YEAR}/GRUPPI-Giovedi(Roberto)/DumaGruppoGiovedi.docx','dst'=>'GRUPPI-GIOVE.docx'], ['src'=>'{YEAR}/GRUPPI-Giovedi(Roberto)/01_GRUPPI-Giove_(Roberto)_Duma_Calc.xlsx','dst'=>'GRUPPI-Giove(Roberto)_Calc.xlsx']],
        'Simba-GRP'         => [['src'=>'{YEAR}/GRUPPI-Domenica(Roberto)/SimbaSafariOgniDomenica.docx','dst'=>'SimbaSafariGruppo.docx'], ['src'=>'{YEAR}/GRUPPI-Domenica(Roberto)/SimbaGRP_Calc.xlsx','dst'=>'SimbaGRP_Calc.xlsx']],
        'PumbaFlyOutZNZ'    => [['src'=>$IT.'SavannahExplorers_PumbaFlyOutZNZSafari.docx','dst'=>'PumbaFlyOutZNZSafari.docx'], ['src'=>$IT.'PumbaFlyOutZNZ_Calc.xlsx','dst'=>'PumbaFlyOutZNZ_Calc.xlsx']],
        'LUXPumbaFlyOutZNZ' => [['src'=>$IT.'SavannahExplorers_LUXPumbaFlyOutZNZSafari.docx','dst'=>'LUXPumbaFlyOutZNZSafari.docx'], ['src'=>$IT.'LUX_PumbaFlyOutZNZ_Calc.xlsx','dst'=>'LUX_PumbaFlyOutZNZ_Calc.xlsx']],
        'LUXSimbaFlyOutZNZ' => [['src'=>$IT.'SavannahExplorers_LUXSimbaFlyOutZNZSafari.docx','dst'=>'LUXSimbaFlyOutZNZSafari.docx'], ['src'=>$IT.'LUX_SimbaFlyOutZNZ_Calc.xlsx','dst'=>'LUX_SimbaFlyOutZNZ_Calc.xlsx']],
    ],

    'Safari + Beach' => [
        'BeachDumaShort'    => [['src'=>$BE.'SavannahExplorers_BeachDumaShortSafari.docx','dst'=>'DumaShortSafari_Zanzibar.docx'], ['src'=>$BE.'BeachDumaShort_Calc.xlsx','dst'=>'BeachDumaShort_Calc.xlsx']],
        'BeachPumba'        => [['src'=>$BE.'SavannahExplorers_BeachPumba.docx','dst'=>'PumbaSafari_Zanzibar.docx'], ['src'=>$BE.'BeachPumba_Calc.xlsx','dst'=>'BeachPumba_Calc.xlsx']],
        'BeachSimba'        => [['src'=>$BE.'SavannahExplorers_BeachSimba.docx','dst'=>'SimbaSafari_Zanzibar.docx'], ['src'=>$BE.'BeachSimba_Calc.xlsx','dst'=>'BeachSimba_Calc.xlsx']],
        'BeachKiboko'       => [['src'=>$BE.'SavannahExplorers_BeachKiboko.docx','dst'=>'BeachKiboko.docx'], ['src'=>$BE.'BeachKiboko_Calc.xlsx','dst'=>'KibokoBeach_Calc.xlsx']],
        'Duma+Pemba'        => [['src'=>$BE.'SavannahExplorers_DumaPembaSafari.docx','dst'=>'DumaPembaSafari.docx'], ['src'=>$BE.'DumaPemba_Calc.xlsx','dst'=>'DumaPemba_Calc.xlsx']],
        'Pumba+Pemba'       => [['src'=>$BE.'SavannahExplorers_PumbaPembaSafari.docx','dst'=>'PumbaPembaSafari.docx'], ['src'=>$BE.'PumbaPemba.xlsx','dst'=>'PumbaPemba_Calc.xlsx']],
        'Simba + Pemba'     => [['src'=>$BE.'SavannahExplorers_PembaSimbaSafari.docx','dst'=>'PembaSimbaSafari.docx'], ['src'=>$BE.'SimbaPemba.xlsx','dst'=>'SimbaPemba_Calc.xlsx']],
        'Zanzibar Safari'   => [['src'=>$BE.'SavannahExplorers_ZanzibarSafari.docx','dst'=>'ZanzibarSafari.docx'], ['src'=>$BE.'ZanzibarSafari.xlsx','dst'=>'ZanzibarSafari_Calc.xlsx']],
    ],

    'Trekking' => [
        'Machame-9 days'    => [['src'=>$KI.'SavannahExplorers_MachameRoute.docx','dst'=>'MachameRoute.docx'], ['src'=>$KI.'MACHAME_7gg_CalcPrice.xls','dst'=>'Machame7gg.xls']],
        'Machame-8 days'    => [['src'=>$KI.'SavannahExplorers_MachameRoute_8gg.docx','dst'=>'MachameRoute8gg.docx'], ['src'=>$KI.'MACHAME_6gg_CalcPrice.xls','dst'=>'Macheme6gg.xls']],
        'Marangu-8 days'    => [['src'=>$KI.'SavannahExplorers_MaranguRoute.doc','dst'=>'MaranguRoute.doc'], ['src'=>$KI.'MARANGU_5&6gg_CalcPrice.xls','dst'=>'MARANGU_5&6gg_CalcPrice.xls']],
        'Marangu-7 days'    => [['src'=>$KI.'SavannahExplorers_MaranguRoute.doc','dst'=>'MaranguRoute7Days.doc'], ['src'=>$KI.'MARANGU_5&6gg_CalcPrice.xls','dst'=>'MARANGU_5gg_CalcPrice.xls']],
        'Rongai-8days'      => [['src'=>$KI.'SavannahExplorers_RongaiRoute.doc','dst'=>'RongaiRoute.doc'], ['src'=>$KI.'RONGAI_5&6gg_CalcPrice.xls','dst'=>'RONGAI_5&6gg_CalcPrice.xls']],
        'Lemosho-9 days'    => [['src'=>$KI.'SavannahExplorers_LemoshoRoute.doc','dst'=>'LemoshoRoute.doc'], ['src'=>$KI.'LEMOSHO_7gg_CalcPrice.xls','dst'=>'LEMOSHO_7gg_CalcPrice.xls']],
        'Lemosho-10 days'   => [['src'=>$KI.'SavannahExplorers_LemoshoRoute_10days.docx','dst'=>'LemoshoRoute_10gg.docx'], ['src'=>$KI.'LEMOSHO_8gg_CalcPrice.xls','dst'=>'LEMOSHO_8gg_CalcPrice.xls']],
    ],

    'LUX & Classic Safari' => [
        'Lux Duma'          => [['src'=>$IT.'SavannahExplorers_DumaSafari.docx','dst'=>'LuxDumaSafari.docx'], ['src'=>$IT.'LUXDuma_Calc.xlsx','dst'=>'LUXDuma_Calc.xlsx']],
        'Lux Pumba'         => [['src'=>$IT.'SavannahExplorers_PumbaSafari.docx','dst'=>'LuxPumbaSafari.docx'], ['src'=>$IT.'LUXPumba_Calc.xlsx','dst'=>'LUXPumba_Calc.xlsx']],
        'Lux Simba'         => [['src'=>$IT.'SavannahExplorers_SimbaSafari.docx','dst'=>'LuxSimbaSafari.docx'], ['src'=>$IT.'LUXSimba_Calc.xlsx','dst'=>'LUXSimba_Calc.xlsx']],
        'Duma Classic'      => [['src'=>$IT.'SavannahExplorers_DumaSafari.docx','dst'=>'ClassicDumaSafari.docx'], ['src'=>$IT.'DumaClassic_Calc.xlsx','dst'=>'DumaClassic_Calc.xlsx']],
        'Pumba Classic'     => [['src'=>$IT.'SavannahExplorers_PumbaSafari.docx','dst'=>'ClassicPumbaSafari.docx'], ['src'=>$IT.'PumbaClassic_Calc.xlsx','dst'=>'PumbaClassic_Calc.xlsx']],
        'Simba Classic'     => [['src'=>$IT.'SavannahExplorers_SimbaSafari.docx','dst'=>'ClassicSimbaSafari.docx'], ['src'=>$IT.'SimbaClassic_Calc.xlsx','dst'=>'SimbaClassic_Calc.xlsx']],
        'Kiboko Classic'    => [['src'=>$IT.'SavannahExplorers_KibokoSafari.docx','dst'=>'ClassicKibokoSafari.docx'], ['src'=>$IT.'KibokoClassic_Calc.xlsx','dst'=>'KibokoClassic_Calc.xlsx']],
    ],
];
