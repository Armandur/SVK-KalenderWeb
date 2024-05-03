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
                        // die('Ingen api-nyckel hittad!');
                }
        }

        $organisations_id = '2020,20271,20270,2025,2023,2024,7640,2022,2026'; //Default organisations-IDn, motsvarar församlingarna i Härnösands pastorat
        $organisation_namn = 'Svenska kyrkan'; //Default organisation
        $webbsida_rubrik = 'Svenska kyrkan'; //Default rubrik
        $max_handelser = '50'; //Max antal händelser att visa i kalendern
	$skrolla = '0'; //Skroll är inaktiverat per default
	$skroll_status = ''; //Tom som standard

        $location_id = '';
        $location_name = '';
        
        $calendarSubGroup = ''; //Händelsetyp ID

        $sluttider = '1';

        // Färger hämtade från Svenska kyrkans grafiska profil
        // https://www.svenskakyrkan.se/grafiskprofil
        $colors = [
                'beige'         =>      '#ffebe1',
                'rosa'          =>      '#ffc3aa',
                'orange'        =>      '#ff785a',
                'vinröd'        =>      '#7d0037',
                
                'ljuslila'      =>      '#cdc3dd',
                'lila'          =>      '#9b87ff',
                'mörklila'      =>      "#412b72",

                'ljusgrön'      =>      '#bee1c8',
                'grön'          =>      '#28a88e',
                'mörkgrön'      =>      '#00554b'
                
        ];
        $color = $colors['vinröd'];

        //Om det finns ett organisations-ID i URL-en
        if (isset ($_GET['orgID']) && $_GET['orgID'] !== '') {
                $organisations_id = $_GET['orgID'];
                //Ta bort allt förutom siffror och kommatecken från ID:et
                $organisations_id = preg_replace("/[^0-9,]/", "", $organisations_id);
        }
        elseif (isset ($_GET['organisationsid']) && $_GET['organisationsid'] !== '') {
                $organisations_id = $_GET['organisationsid'];
                //Ta bort allt förutom siffror och kommatecken från ID:et
                $organisations_id =preg_replace("/[^0-9,]/", "", $organisations_id);
        }

        //Möjlighet att filtrera aktiviteter per locationID
        if (isset($_GET['locationID']) && $_GET['locationID'] !== '') {
                $location_id = $_GET['locationID'];
        }

        //Filtrering via namn på Händelsetyp
        // 101 = Gudstjänst & mässa
        // 102 = Mötas & umgås
        // 103 = Kropp & själ
        // 104 = Barnverksamhet
        // 105 = Musik & kör
        // 106 = Stöd & omsorg
        // 107 = Konst& kultur
        // 108 = Studier & samtal
        // 109 = Skapande och kreativitet
        // 110 = Ungdomsverksamhet
        // 111 = Drop-in
        if (isset($_GET['csg']) && $_GET['csg'] !== '') {
                $calendarSubGroup = explode(',', $_GET['csg']); //Hantera flera Händelsetyper för or-filtrering i api:t
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
                        $color = $colors['vinröd'];
                }
        }
		
        //Möjlighet att sätta eget antal händelser att ladda
        if (isset($_GET['antal']) && is_numeric($_GET['antal']) && $_GET['antal'] > '0' && $_GET['antal'] < '100') {
            $max_handelser = $_GET['antal'];
        }
		
        //Möjlighet att aktivera skroll på webbsidan
	if (isset($_GET['skrolla'])) {
            $skrolla = '1';
			$skroll_status = ' class="skrolla"';
        }
		
        //Möjlighet att ta bort alla sluttider
        if (isset($_GET['sluttider']) && $_GET['sluttider'] == 'nej') {
            $sluttider = '0';
        }
		
		//echo '<!-- Sluttider status: '.$sluttider.' -->';
		
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

        function translate_weekday($weekday) {
                return str_ireplace(array('Monday','Tuesday','Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), array('Måndag','Tisdag','Onsdag','Torsdag','Fredag','Lördag','Söndag'),$weekday);
        }

        function translate_month($month) {
                return str_ireplace(array('Jan','Feb','Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'), array('januari','februari','mars','april','maj','juni','juli', 'augusti', 'september', 'oktober', 'november', 'december'), $month);
        }

        //Länk till kalenderdatan
        $url = 'https://api.svenskakyrkan.se/calendarsearch/v4/SearchByParent?apikey='.$api_nyckel.'&orgId='.$organisations_id.'&$orderby=StartTime';

        if ($location_id !== '') { //Låt API:t filtrera location_id om vi filtrerar på ett sådant.
                // %20 = space
                // %27 = '
                $url .= '&$filter=Place/Id%20eq%20%27'.$location_id.'%27';

                if ($calendarSubGroup != '') { //Vi filtrerar på både plats och händelsetyp.
                        $url .= '%20and%20CalendarSubGroups/any(n:n/Id%20eq%20';

                        for($i = 0; $i < count($calendarSubGroup); $i++) {
                                if($i > 0) {
                                        $url .= '%20or%20n/Id%20eq%20';
                                }
                                $url .= $calendarSubGroup[$i];
                        }
                        $url .= ')';
                }
        }
        elseif ($location_id == '' && $calendarSubGroup != ''){ //Vi filtrerar inte på plats, men på händelsetyp.
                $url .= '&$filter=CalendarSubGroups/any(n:n/Id%20eq%20';

                for($i = 0; $i < count($calendarSubGroup); $i++) {
                        if($i > 0) {
                                $url .= '%20or%20n/Id%20eq%20';
                        }
                        $url .= $calendarSubGroup[$i];
                }
                $url .= ')';
        }

        $api_url = 'https://svk-apim-prod.azure-api.net/calendar/v1/event/search?subscription-key='.$api_nyckel.'&limit=50'.'&expand=*'.'&owner_id='.$organisations_id.'&from=now'/*.'&to=2w'*/;
        # expand=* Tar med t.ex. platsnamnet, inte bara platsID - Underlättar :)

        if ($location_id !== '') { //Låt API:t filtrera location_id om vi filtrerar på ett sådant.
                $api_url .= '&place_id='.$location_id; # I nya api:t kan det vara abc123 eller abc123,abc456,abc789 - den senare tar händelser från alla tre platser
        }
        # Härnösands domkyrka = 5dab016f-18f3-4973-92d8-69779653a1ef

        //TODO: Bygg query baserat på url-params
        $query = "";
        # echo($api_url);
        $response = file_get_contents($api_url);
        #echo($response);
        
        #Konvertera till array
        $response = json_decode($response, true); # Här finns också länk till att hämta nästa 50 [LIMIT] resultat i $response['next']
        $calendar = "";

        $events_by_date = array();

        if($response === FALSE)
        {
                $calendar = "Ett API-anrop fungerade inte.";
        }
        else
        {
                $events = $response['result'];
                $events_count = count($events);
                
                echo '<pre>';
                foreach ($events as $event)
                {
                        $starttid = str_replace(':', '.', substr($event['startLocalTime']['time'], 0, 5)); # Gör om hh:MM:ss till hh.MM
                        $sluttid = str_replace(':', '.', substr($event['endLocalTime']['time'], 0, 5)); # Sluttid är tvingande i nya API:t så vi behöver inte kolla om det finns, det finns _alltid_ :)
                        
                        $medverkande = "";
                        if (isset($event['performers']))
                        {
                                foreach ($event['performers'] as $person)
                                {
                                        $medverkande .= $person['title'].': '.$person['name'].', '; # Kommatecken och mellanslag efter varje medverkande.
                                }
                                $medverkande = rtrim(trim($medverkande), ','); #Ta bort sista kommatecknet och mellanslag för att hålla $medverkande "ren".
                        }
                        
                        $beskrivning = "";
                        if (array_key_exists('description', $event)) # Om det finns beskrivning.
                        {
                                $beskrivning = $event['description']; # Verkar vara ren text nu i nya API:t, dvs ingen formatering, ev bara när det kommer från bokningssystem?
                                
                                # Nä förresten, radbrytningar finns fortfarande - som \r\n
                                # TODO: Kolla på aktiviter om man använder kalenderadmin, kan man formatera där och blir det då html i returnerad händelse från apit?

                                $beskrivning = str_ireplace(array("\r", "\n"), array('', ' '), $beskrivning);
                                
                                /*
                                $beskrivning = str_ireplace(array('<B>', '</B>', '<BR /><BR />', '<BR />', '<BR>', '. . ', '.. '), array('', '', '', '. ', '. ', '. ', '. '), $beskrivning);
                                $beskrivning = preg_replace('#<a.*?>.*?</a>#i', '', $beskrivning);
                                */
                        }
                        $datum = $event['startLocalTime']['date'];
                        //print($datum.' - '.$starttid.'-'.$sluttid.' '.$event['title'].', '.$event['place']['name'].' - '.$medverkande.' - '.$beskrivning);
                        //print("\n");

                        $extracted_event = array(
                                "date" => $datum,
                                "start" => $starttid,
                                "end" => $sluttid,
                                "title" => $event["title"],
                                "place" => $event["place"]["name"],
                                "participants" => $medverkande,
                                "description" => $beskrivning
                        );

                        // Gör en nyckel i arrayet per datum så att vi kan sortera upp aktiviteterna per veckodag sedan vid visningen
                        if (!array_key_exists($event['startLocalTime']['date'], $events_by_date)) {
                                $events_by_date[$event['startLocalTime']['date']] = array(); //Om inte veckodagen finns som nyckel, skapa ett nytt array att lägga till aktivteter i med datumet som nyckel
                                array_push($events_by_date[$event['startLocalTime']['date']], $extracted_event);
                        }
                        else {
                                array_push($events_by_date[$event['startLocalTime']['date']], $extracted_event);
                        }
                }

                foreach( $events_by_date as $date ) {
                        $block = "";
                        foreach( $date as $event ) {
                                $formatted_date = translate_weekday(translate_month(date('l j M',strtotime($event["date"]))));
                                if ($event == $date[0]) {
                                        //print_r("Första händelsen för veckodagen");
                                        $idag_imorgon = "";
                                        if($event["date"] == date('Y-m-d'))
                                        {
                                                $idag_imorgon = 'I dag ';
                                                $formatted_date = strtolower($formatted_date);
                                        }
                                        else if ($event['date'] == date('Y-m-d', strtotime('+1 day')))
                                        {
                                                $idag_imorgon = 'I morgon ';
                                                $formatted_date = strtolower($formatted_date);
                                        }
                                        $block .= '<div class="datum"><h2>'.$idag_imorgon.$formatted_date.'</h2></div>'."\n";
                                }
                                if(isset($event["end"])) {
                                        $event["end"] = '-'.$event["end"];
                                }
                                
                                $block .= '<div class="handelse"><p><span class="fet">'.$event["start"].$event["end"].' '.$event["title"].'</span>';
                                
                                if ($location_id == '') {
                                        $block .= ', '.$event["place"];
                                }

                                if ($event["participants"] != "") {
                                        $event["participants"] = $event["participants"].'.';
                                }

                                $block .= '<span class="infotext"> '.$event["participants"].' '.$event["description"].'</span></p></div>';
                        }
                        $kalender .= $block;
                }
                //print_r($events_by_date);
                echo '</pre>';
        }
?>
<html>
	<head>

			<meta charset="utf-8">

			<title>Kalender för <?php echo $organisation_namn; ?></title>

			<link rel="stylesheet" type="text/css" href="style.css" media="all" />

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
							//echo("color: ".$color.";");
							?>
					}
                </style>
	</head>
	<body<?php echo $skroll_status; ?>>

			<div id="header">
					<?php 
                                        
					echo('<h1>'.$webbsida_rubrik.'</h1>');
					if ($location_id !== '') {
							echo('<h1 class="location">'.$location_name.'</h1>');
					}

					?>
			</div>

			<div id="wrapper"<?php echo $skroll_status; ?>>

				<?php echo $kalender; ?>

			</div>

			<?php  if ($skrolla == '0') { echo '<div id="gradient"></div>'; } ?>

	</body>
</html>
