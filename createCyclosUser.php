<?php

require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" and isset($_POST['login'])) {
	$credential = $gonette_cyclos_user . ":" . $gonette_cyclos_pwd;
	print "1ère étape : vérifier si l'utilisateur n'existe pas déjà <br>";
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
		$gone = new Adherent($db);
		$extrafields = new ExtraFields($db);
		$extralabels = $extrafields->fetch_name_optionals_label($gone->table_element);
		$rowid = $_POST['id'];
		$gone->fetch($rowid);
		$gone->fetch_optionals($rowid, $extralabels);

		// about to create a user

		// Prepare some data to be posted
		// Choose gender
		$genre = $gonette_cyclos_gender_mme;

		if ($gone->civility_id == "MR") {
			$genre = $gonette_cyclos_gender_mr;

			//TODO : dynamically retrieve ID via users/data-for-new endpoint of Cyclos API instead of storing it in env variables
		}
		// Prepare temp password
		$password = substr($gone->array_options['options_sepa_email'], 2, 2) . rand(10000, 99999);

		// Fill in POST request
		$customFields = array(
			"gender" => $genre,
			"lastname" => $gone->lastname,
			"firstname" => $gone->firstname,
			"numadherent" => $gone->login,
			"RUM" => $gone->array_options['options_sepa_rum_change']
		);
		$passwordField = array(
			"type" => "login",
			"value" => $password,
			"forceChange" => true
		);
		$address = array(
			"name" => "Adresse principale",
			"addressLine1" => $gone->array_options['options_sepa_address'],
			"zip" => $gone->array_options['options_sepa_zip_code'],
			"city" => $gone->array_options['options_sepa_city']
		);
		$data = array(
			"name" => $gone->array_options['options_sepa_name_first_name'],
			"customValues" => $customFields,
			"username" => $gone->login,
			"field.numadherent" => $gone->login,
			"email" => $gone->array_options['options_sepa_email'],
			"passwords" => $passwordField,
			"addresses" => $address,
			"group" => "A2ParticulierCompte",
			"skipActivationEmail" => "true"

		);
		$data_string = json_encode($data);

		$server_output = runPost($gonette_cyclos_api_url . 'users', $data_string, $credential);

		print "Réponse du server: " . $server_output;
		print "<br><br>2ème étape : si le message dessus contient un ID, alors l'utilisateur est créé \o/. <br>";
		print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.<br>";

		print "<br>3ème étape : création du token (QR code numéro adhérent)";

		$data = array(
			"user" => $gone->array_options['options_sepa_email'],
			"value" => $gone->login,
			"activateNow" => true
		);
		$data_string = json_encode($data);
		$server_output = runPost($gonette_cyclos_api_url . 'tokens/NumQRCode/new', $data_string, $credential);
		print "<br>Réponse du server: " . $server_output;
		print "<br><br>3ème étape : si le message dessus contient un ID, alors le token est créé \o/. <br>";
		print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.";

	} else {
		print "l'utilisateur existe déjà dans cyclos avec ce numéro d'adhérent <br> " . $getUserResponse;
	}
} else {
	print "Erreur de chargement, merci de réessayer";
}

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
