<?php

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" and isset($_POST['login'])) {

	$credential = $gonette_cyclos_user . ":" . $gonette_cyclos_pwd;
	print "1ère étape : vérifier si le pro n'existe pas déjà <br>";
	$cURLConnection = curl_init();
	$geturl = $gonette_cyclos_api_url . 'users?fields=id&addressResult=none&keywords=' . urlencode($_POST['login']) . '&orderBy=relevance&profileFields=username';
	curl_setopt($cURLConnection, CURLOPT_URL, $geturl);
	curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($cURLConnection, CURLOPT_USERPWD, $credential);
	$getUserResponse = curl_exec($cURLConnection);
	curl_close($cURLConnection);

	if ($getUserResponse == '{"code":"login","passwordStatus":"temporarilyBlocked"}') {
		print "l'utilisateur technique est bloqué pour quelques minutes, mot de passe incorrect <br>";
	}

	if ($getUserResponse == '{"code":"login"}') {
		print "mauvais mot de passe pour l'utilisateur technique <br>";
	}

	if ($getUserResponse == "[]") {
		print "1ère étape : OK <br>";
		print "2ème étape : tentative de création de l'utilisateur <br>";

		// Fetch data from database (entreprise, extrafields, adherent)
		$object = new Societe($db);
		$extrafields = new ExtraFields($db);
		$extralabels = $extrafields->fetch_name_optionals_label($object->table_element);
		$rowid = $_POST['rowId'];
		$object->fetch($rowid);
		$object->fetch_optionals($rowid, $extralabels);
		$adh = new Adherent($db);
		$adh->fetch('', '', $object->id);

		// compute categories
		$mainCategory = $object->array_options['options_maincategory'];
		$sideCategories = $object->array_options['options_sidecategories'];

		$cyclosMainCategory = convertCategories($mainCategory);

		$cyclosSideCategories = [];
		if (isset($sideCategories)) {
			$sideCategoriesArray = explode(",", $sideCategories);
			foreach ($sideCategoriesArray as $dolibarrSideCategory) {
				array_push($cyclosSideCategories, convertCategories($dolibarrSideCategory));
				$cyclosMainCategory = $cyclosMainCategory . "|" . convertCategories($dolibarrSideCategory);
			}
		}

		$allCategories = $cyclosMainCategory;

		// Exchange office
		$exchangeOfficeDolibarr = $object->array_options['options_exchangeoffice'];

		$exchangeOffice = "";
		if (isset($exchangeOfficeDolibarr) && $exchangeOffice == "1") {
			$exchangeOffice = "partenairebureaudechange";
		}

		// Choose gender
		$genre = $gonette_cyclos_gender_mme;
		if ($adh->civility_id == "MR") {
			$genre = $gonette_cyclos_gender_mr;
			//TODO : dynamically retrieve ID via users/data-for-new endpoint of Cyclos API instead of storing it in env variables
		}
		// Prepare temp password
		$password = substr($object->email, 2, 2) . rand(10000, 99999);
		$website = $object->url;
		if (isset($website) && substr($website, 0, 4) != "http") {
			$website = "http://" . $website;
		}
		$gonetteTypeAccepted = '1258852005474184473'; // billet coupon
		// Fill in POST request
		$customFields = array(
			"catpro" => $allCategories,
			"gender" => $genre,
			"bureauchange" => $exchangeOffice,
			"proname" => $object->nom,
			"lastname" => $adh->lastname,
			"firstname" => $adh->firstname,
			"numadherent" => $object->code_client,
			"aboutme" => $object->array_options['options_shortdescription'],
			"prodescription" => $object->array_options['options_description'],
			"openingtime" => $object->array_options['options_openinghours'],
			"website" => $website,
			"PaymentAccept" => $gonetteTypeAccepted
		);
		$passwordField = array(
			"type" => "login",
			"value" => $password,
			"forceChange" => true
		);
		$address = array(
			"name" => "Adresse principale",
			"addressLine1" => $object->address,
			"zip" => $object->zip,
			"city" => $object->town
		);
		$data = array(
			"name" => $object->nom,
			"customValues" => $customFields,
			"username" => $object->code_client,
			"email" => $object->email,
			"passwords" => $passwordField,
			"addresses" => $address,
			"group" => "B2ProfessionnelCompte",
			"skipActivationEmail" => "true"
		);
		$data_string = json_encode($data);
		$server_output = runPost($gonette_cyclos_api_url . 'users', $data_string, $credential);

		print "Réponse du server: " . $server_output;
		print "<br><br>2ème étape : si le message dessus contient un ID, alors l'utilisateur est créé \o/. <br>";
		print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.<br>";

		print "<br>3ème étape : création du token (QR code numéro adhérent)";

		$data = array(
			"user" => $object->email,
			"value" => $object->code_client,
			"activateNow" => true
		);
		$data_string = json_encode($data);
		$server_output = runPost($gonette_cyclos_api_url . 'tokens/NumQRCode/new', $data_string, $credential);
		print "<br>Réponse du server: " . $server_output;
		print "<br><br>3ème étape : si le message dessus contient un ID, alors le token est créé \o/. <br>";
		print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.";

		// Check if address creation worked
		$addressUrl = $gonette_cyclos_api_url . $object->email . '/addresses';
		$address_existing_server_output = runGet($addressUrl, $credential);
		if ($address_existing_server_output == "[]") {
			print "<br>Aucune adresse trouvée. Prochaine étape : création de l'adresse.";

			$address = array(
				"name" => "Adresse principale",
				"addressLine1" => $object->address,
				"zip" => $object->zip,
				"city" => $object->town,
			);
			$data_string = json_encode($address);
			$server_output = runPost($gonette_cyclos_api_url . $object->email . '/addresses', $data_string, $credential);

			print "<br>Si la ligne dessous contient un ID, alors la création a fonctionnée: <br>" . $server_output;
			exit();
		}

	} else {
		print "l'utilisateur existe déjà dans cyclos avec ce numéro d'adhérent <br> " . $getUserResponse;
	}
} else {
	print "Erreur de chargement, merci de réessayer";
}

/**
 * Util function to run cURL POST request
 * @param $apiUrl : complete URL to execute the request
 * @param $data_string : body of the request, under json form
 * @param $credential : credential to log into target API
 */
function runPost($apiUrl, $data_string, $credential)
{
	$cURLConnection = curl_init();
	curl_setopt($cURLConnection, CURLOPT_URL, $apiUrl);
	curl_setopt($cURLConnection, CURLOPT_POST, 1);
	curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, $data_string);
	curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($cURLConnection, CURLOPT_USERPWD, $credential);
	curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
	$server_output = curl_exec($cURLConnection);
	curl_close($cURLConnection);
	return $server_output;
}

/**
 * Convert Dolibarr pro category into Cyclos pro category
 */
function convertCategories($dolibarrCategory)
{
	if ($dolibarrCategory < 18) {
		$cyclosCategory = 'cat' . $dolibarrCategory;
	} else {
		/*
		 * Need to convert category numbers because there is a mismatch between Dolibarr and Cyclos.
		 * Detail of mapping is in CSV file doc/mapping_pro_dolibarr_cyclos.csv
		 */
		$mapOddCategories = array(
			"19" => "cat18",
			"20" => "cat19",
			"21" => "cat20",
			"22" => "cat21",
			"24" => "cat24",
			"25" => "cat25",
			"26" => "cat26",
			"27" => "cat27",
			"28" => "cat28",
			"29" => "cat29",
		);
		$cyclosCategory = $mapOddCategories[$dolibarrCategory];
	}
	return $cyclosCategory;
}
