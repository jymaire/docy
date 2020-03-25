<?php
    // import some classes specif to Gonette
    require_once '../main.inc.php';
    require_once DOL_DOCUMENT_ROOT . '/public/members/adhesion/gonAdherent.class.php';
    require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

    // basic auth, credentials are stored elsewhere
    $credential = $cyclos_user.":".$cyclos_pwd;  
    print "1ère étape : vérifier si le pro n'existe pas déjà <br>";
    $cURLConnection = curl_init();
    $geturl = $cyclos_api_url.'users?fields=id&addressResult=none&keywords='.urlencode($_POST['login']). '&orderBy=relevance&profileFields=username';
    curl_setopt($cURLConnection, CURLOPT_URL,$geturl);
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

        // Fetch data from database (entreprise, extrafields, adherent) (also some are specic to Gonette)
        $object = new Societe($db);
        $extrafields = new ExtraFields($db);
        $extralabels=$extrafields->fetch_name_optionals_label($object->table_element);
        $rowid  = $_POST['rowId'];
        $object->fetch($rowid);
        $object->fetch_optionals($rowid,$extralabels);
        $adh=new Adherent($db);
        $adh->fetch('','',$object->id);

        // compute categories
        $mainCategory = $object->array_options['options_maincategory']; 
        $sideCategories = $object->array_options['options_sidecategories']; 

        $cyclosMainCategory = convertCategories($mainCategory);

        $cyclosSideCategories = [];
        if (isset($sideCategories)) {
            $sideCategoriesArray = explode(",", $sideCategories);
            foreach($sideCategoriesArray as $dolibarrSideCategory) {
                array_push($cyclosSideCategories, convertCategories($dolibarrSideCategory));
                $cyclosMainCategory = $cyclosMainCategory ."|". convertCategories($dolibarrSideCategory);
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
        $genre = $cyclos_gender_mme;
        if ($adh->civility_id == "MR") {
            $genre = $cyclos_gender_mr;

            //TODO : dynamically retrieve ID via users/data-for-new endpoint of Cyclos API instead of storing it in env variables
            // For the moment, ID are stored in environment file. Below the request to get it
            // curl -X GET "https://www.mycitycash.fr/gonettev2/api/users/data-for-new?group=A2ParticulierCompte" -H  "accept: application/json"
        }
        // Prepare temp password
        $password = substr($object->email,2,2). rand(10000,99999);
        // Website are stored with protocol in our Dolibarr, so need to add some protocol
        $website =  $object->url;
        if (isset($website) && substr($website,0,4) != "http") {
            $website = "http://".$website;
        }
        // Fill in POST request
        // All Gonette custom fields
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
            "website" => $website
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
            // Here is the group of the user (to make difference between person and profesional)
            "group" => "ProfessionalAccount",
            "skipActivationEmail" => "true"
        );                     
        // Don't forget to encode your data, to have a valid request                                             
        $data_string = json_encode($data);
        $server_output = runPost($cyclos_api_url.'users',$data_string,$credential);
        
        print "Réponse du server: ".$server_output;
        print "<br><br>2ème étape : si le message dessus contient un ID, alors l'utilisateur est créé \o/. <br>";
        print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.<br>";

        // create a token to identify the user
        print "<br>3ème étape : création du token (QR code numéro adhérent)";

        $data = array(
            "user" => $object->email,
            "value" => $object->code_client,
            "activateNow" => true
        );
        $data_string = json_encode($data);
        $server_output = runPost($cyclos_api_url.'tokens/NumQRCode/new',$data_string,$credential);
        print "<br>Réponse du server: ".$server_output;
        print "<br><br>3ème étape : si le message dessus contient un ID, alors le token est créé \o/. <br>";
        print "Dans le cas contraire, merci de copier cette page et la transmettre à un développeur.";

    } else {
        print "l'utilisateur existe déjà dans cyclos avec ce numéro d'adhérent <br> ".$getUserResponse;
    }

    /**
     * Util function to run cURL POST request
     * @param $apiUrl : complete URL to execute the request
     * @param $data_string : body of the request, under json form
     * @param $credential : credential to log into target API
     */
    function runPost($apiUrl, $data_string, $credential){
        $cURLConnection = curl_init();
        curl_setopt($cURLConnection, CURLOPT_URL, $apiUrl);
        curl_setopt($cURLConnection, CURLOPT_POST, 1);
        curl_setopt($cURLConnection, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($cURLConnection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cURLConnection, CURLOPT_USERPWD, $credential);  
        curl_setopt($cURLConnection, CURLOPT_HTTPHEADER, Array("Content-Type: application/json")); 
        $server_output = curl_exec($cURLConnection);
        curl_close ($cURLConnection);
        return $server_output;
    }

    /**
     * Convert Dolibarr pro category into Cyclos pro category
     * Specific to Gonette
     */
    function convertCategories($dolibarrCategory){
        if ($dolibarrCategory < 18) {
            $cyclosCategory = 'cat'.$dolibarrCategory;
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
