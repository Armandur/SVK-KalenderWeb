<?php
        $api_nyckel = '';

        // Lägg api-nyckeln som text i en fil du döper till api och lägger jämte index.php
        // OBS VIKTIGT! Se till i webbserverns inställningar att filen inte exponeras på internet! (.htaccess m.m.)
        if (file_exists('api')) {
                $file = fopen('api', 'r') or die('Kunde inte öppna api-nyckelfil');
                $api_nyckel = trim(fgets($file));
                fclose($file);
        }
        else { //Finns ingen api-fil behöver du lägga api-nyckeln som get parameter i länken, ?api=abc123
                if (isset($_GET['api']) && $_GET['api'] !== '') {
                        $api_nyckel = $_GET['api'];
                }
                else {
                        die('Ingen api-nyckel hittad!');
                }
        }

        $organisations_id = '20271'; //Default organisations-ID
        $organisation_namn = 'Svenska kyrkan'; //Default organisation
        $webbsida_rubrik = 'Svenska kyrkan'; //Default rubrik
        $max_handelser = '50'; //Max antal händelser att visa i kalendern

        $location_id = '';
        $location_name = '';

        $colors = [
                'kyrkröd' => '#cd0014',
                'kyrkblå' => '#006fb9',
                'kyrkgrön' => '#6b9531',
                'kyrklila' => '#522583',
                'kyrkgul' => '#f59c00',
        ];
        $color = $colors['kyrkröd'];

        //Om det finns ett organisations-ID i URL-en
        if (isset ($_GET['orgID']) && $_GET['orgID'] !== '') {
                $organisations_id = $_GET['orgID'];
                //Ta bort allt förutom siffror och kommatecken från ID:et
                $organisations_id = preg_replace("/[^0-9,]/", "", $organisations_id);
        }

        //Möjlighet att filtrera aktiviteter per locationID
        /*
                Härnösands domkyrka = 5dab016f-18f3-4973-92d8-69779653a1ef
                Säbrå kyrka = 26a876d9-3cf6-4a8b-8e11-7c78eaaef4d9
        */
        if (isset($_GET['locationID']) && $_GET['locationID'] !== '') {
                $location_id = $_GET['locationID'];
        }

        //Möjlighet att sätta Title via parameter ?orgName=Svenska kyrkan Härnösand
        if (isset($_GET['orgName']) && $_GET['orgName'] !== '') {
                $organisation_namn = $_GET['orgName'];
        }

        //Möjlighet att sätta kalenderrubriken via parameter ?header=Svenska kyrkan Härnösand
        if (isset($_GET['header']) && $_GET['header'] !== '') {
                $webbsida_rubrik = $_GET['header'];
        }

        if (isset($_GET['color']) && $_GET['color'] !== '')
        {
                try {
                        $color = $colors[$_GET['color']];
                } catch (Exception $e) {
                        $color = $colors['kyrkröd'];
                }
        }

        //Börja med att starta en session
        session_start();

        //Headers
        header('Content-type: text/html; charset=utf-8');

        //Tidszon
        date_default_timezone_set('Europe/Stockholm');

        //Aktuellt datum och tid
        $aktuellt_datum = date('Ymd');
        $aktuell_tid = date('H.i');

        $datum_imorgon = date("Ymd", strtotime("tomorrow"));

        $hitta_passerad_tid = strtotime('-2 hour');
        $passerad_tid = date("H.i", $hitta_passerad_tid);

        //Grundvariabler
        $kalender = '';
        $antal_hittade = '0';
        $kalender_resultat = '';
        $medverkande = '';

        $datumArray = array();

        $antal_tillagda = '0';

        //Länk till kalenderdatan
        $url = 'https://api.svenskakyrkan.se/calendarsearch/v4/SearchByParent?apikey='.$api_nyckel.'&orgid='.$organisations_id.'&$orderby=StartTime';
        if ($location_id !== '') { //Låt API:t filtrera location_id om vi filtrerar på ett sådant.
                $url .= '&$filter=Place/Id%20eq%20%27'.$location_id.'%27';
        }

        $kalender_api_lank = file_get_contents($url);

        //Om API-länken inte fungerar
        if($kalender_api_lank === FALSE) {
                $kalender = 'Ett API-anrop fungerade inte, vi beklagar.';
        }
        else {
                //Gör om JSON till en array
                $svk_kalender_array = json_decode ($kalender_api_lank, true);

                //Antal aktiviteter i kalendern
                $antal_aktiviteter  = count($svk_kalender_array['value']);

                //Ta bort 1 från antalet, första raden är ju 0
                $antal_aktiviteter--;

                //Om det finns några aktiviteter att loopa
                if ($antal_aktiviteter > '0') {

                        //Loopa igenom alla aktiviteter
                        for ($ladda_aktivitet = 0; $ladda_aktivitet <= $antal_aktiviteter; $ladda_aktivitet++) {
                                $startdatum = $svk_kalender_array['value'][$ladda_aktivitet]['StartTime'];
                                $starttid = $svk_kalender_array['value'][$ladda_aktivitet]['EventTime'];
                                $slutdatum = $svk_kalender_array['value'][$ladda_aktivitet]['StopTime'];
                                $titel = $svk_kalender_array['value'][$ladda_aktivitet]['Title'];
                                $beskrivning = $svk_kalender_array['value'][$ladda_aktivitet]['Description'];
                                $plats = $svk_kalender_array['value'][$ladda_aktivitet]['PlaceDescription'];
                                $raderad = $svk_kalender_array['value'][$ladda_aktivitet]['Deleted'];

                                if ($location_id !== '') {
                                        $location_name = $plats;
                                }

                                //Om det inte redan har lagts till max antal aktiviteter i kalendern och denna aktivitet INTE är raderad
                                if ($antal_tillagda < $max_handelser && empty($raderad)) {

                                        //Bara datum i start- och sluttiderna
                                        $startdatum = substr($startdatum, 0, strpos($startdatum, 'T'));
                                        $slutdatum = substr($slutdatum, 0, strpos($slutdatum, 'T'));
                                        //Enbart siffror i datumen
                                        $startdatum = str_replace(array('-'), array(''), $startdatum);
                                        $slutdatum = str_replace(array('-'), array(''), $slutdatum);
                                        //Byt kolon mot punkt i starttiden
                                        $starttid = str_replace(array(':', ' '), array('.', ''), $starttid);

                                        //Om det finns en sluttid
                                        if (strpos($starttid, '-') !== false) {
                                                $starttid_ratt = strtok($starttid, '-');
                                                $sluttid = str_replace(array($starttid_ratt.'-'), array(''), $starttid);
                                                $sluta_visas = $sluttid;
                                                $sluttid = '-'.$sluttid;
                                                $starttid = $starttid_ratt;
                                        }
                                        else {
                                                $sluttid = '';
                                                $sluta_visas = date($starttid, strtotime('+2 hours'));
                                        }

                                        //Om aktiviteten inte är passerad
                                        if ($slutdatum > $aktuellt_datum || ($slutdatum == $aktuellt_datum && $sluta_visas >= $aktuell_tid)) {

                                                //Ta bort rum (eller annat efter ett kommatecken) i plats
                                                if (strpos($plats, ',') !== false) {
                                                        $plats = substr($plats, 0, strpos($plats, ','));
                                                }

                                                //Lägg till ett kommatecken om det finns en plats och att vi inte filtrerar på platsID
                                                if (($location_id == '') && (!empty($plats) && $plats !== '')) {
                                                        $plats = ', '.$plats;
                                                }

                                                //Om det finns en beskrivning
                                                if (!empty($beskrivning)) {
                                                        //Ingen HTML i beskrivningen
                                                        $beskrivning = str_ireplace(array('<B>', '</B>', '<BR /><BR />', '<BR />', '<BR>', '. . ', '.. '), array('', '', '', '. ', '. ', '. ', '. '), $beskrivning);
                                                        $beskrivning = preg_replace('#<a.*?>.*?</a>#i', '', $beskrivning);
                                                }
                                                else {
                                                        $beskrivning = '';
                                                }
                                                //Lägg till infotext och ev. beskrivning
                                                $beskrivning = '<span class="infotext"> '.$medverkande.' '.$beskrivning.'</span>';

                                                //Bättre format på datumet
                                                $startdatum_visning = date('l j M',strtotime($startdatum));

                                                //Om det är dagens datum
                                                if ($startdatum == $aktuellt_datum) {
                                                        $startdatum_visning = 'I dag '.$startdatum_visning;
                                                }
                                                //Om det är morgondagens datum
                                                elseif ($startdatum == $datum_imorgon) {
                                                        $startdatum_visning = 'I morgon '.$startdatum_visning;
                                                }

                                                //Översätt veckodagar till svenska
                                                $startdatum_visning = str_ireplace(array('Monday','Tuesday','Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), array('Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag','Söndag'),$startdatum_visning);

                                                //Översätt månader till svenska
                                                $startdatum_visning = str_ireplace(array('Jan','Feb','Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'), array('januari','februari','mars','april','maj','juni','juli', 'augusti', 'september', 'oktober', 'november', 'december'),$startdatum_visning);

                                                //Om det är den första händelsen detta datum
                                                if (!in_Array($startdatum, $datumArray)) {

                                                        //Lägg till en datumrubrik
                                                        $kalender .= '<div class="datum"><h2>'.$startdatum_visning.'</h2></div>'."\n";

                                                        //Lägg in datumet i arrayen
                                                        $datumArray[] = $startdatum;

                                                }
                                                //Lägg till händelsen i kalendern
                                                $line = '<div class="handelse"><p><span class="fet">'.$starttid.$sluttid.' '.$titel.'</span>';
                                                if ($location_id == '') {
                                                        $line .= $plats;
                                                }
                                                $line .= $beskrivning.'</p></div>'."\n";
                                                $kalender .= $line;

                                                $antal_tillagda++;
                                        }
                                }
                        }
                }
                //Om det inte finns några aktiviteter
                else {
                        $kalender = 'Ett fel uppstod och kalendern kunde inte laddas. Vi beklagar detta.';
                }
        }
?>
<html>
	<head>

			<meta charset="utf-8">

			<title>Kalender för <?php echo $organisation_namn; ?></title>

			<link rel="stylesheet" type="text/css" href="/style.css" media="all" />

			<!-- fix för mobiler -->
		<meta name="viewport" content="width=device-width; initial-scale=1; maximum-scale=1">
                <style>
                        #header {
                                <?php 
                                echo("background: ".$color.";");
                                ?>
                        }

                        .datum {
                                <?php 
                                echo("color: ".$color.";");
                                ?>
                        }
                </style>
	</head>
	<body>

			<div id="header">
					<?php 
                                        
                                        echo('<h1>'.$webbsida_rubrik.'</h1>');
                                        if ($location_id !== '') {
                                                echo('<h1 class="location">'.$location_name.'</h1>');
                                        }

                                        ?>
			</div>

			<div id="wrapper">

					<?php echo $kalender; ?>

			</div>

			<div id="gradient"></div>

	</body>
</html>