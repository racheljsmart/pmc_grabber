<?php
ini_set('max_execution_time', 300); // 5 minute execution time on script
$sleepVar = 10; // seconds to sleep, use with sleep();

// Construct a valid search that brings back results associated with
// the Grant # HD052120 and Affiliations: [Florida State University; FSU; 
// Florida Center for Reading Research; FCRR]
// The search will return a list of matching IDs; use those IDs to iterate 
// through another API call to get the specific info per article

$combined_search = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=(HD052120%5BGrant+Number%5D+AND+FCRR%5BAffiliation%5D)+OR+(HD052120%5BGrant+Number%5D+AND+(Florida+Center+for+Reading+Research%5BAffiliation%5D))+OR+(HD052120%5BGrant+Number%5D+AND+FSU%5BAffiliation%5D)+OR+(HD052120%5BGrant+Number%5D+AND+(Florida+State+University%5BAffiliation%5D))";
$response_search = file_get_contents($combined_search) or die("Problem with eSearch");
$json_response = json_decode($response_search);

$count = $json_response->esearchresult->count; // Number of results fetched

// Create the ID List String to pass to eSummary
// Store in:  $idList

$idList = "";
$i = "";
for ($i = 0; $i < $count; $i++){
    $idList .= "{$json_response->esearchresult->idlist[$i]}";
    if($i != ($count - 1)){
    $idList .= ",";
    }
}

sleep($sleepVar); // Give the server some time to rest

// Construct eSummary request & decode the JSON
$eSum = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&retmode=json&id={$idList}";
$eSumResponse = file_get_contents($eSum) or die("Problem with eSummary");
$json_eSum = json_decode($eSumResponse);

sleep($sleepVar); // Sleep time between server calls

// Construct eFetch request and store in XML variable
// eFetch does not support returning JSON unfortunately
$eFetch = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&retmode=xml&id={$idList}";
$eFetchXML = simplexml_load_file($eFetch) or die ("Problem with loading XML from eFetch");

// IMPORTANT TO REALIZE AT THIS POINT
// The JSON array is Keyed via the UID, but the XML array is NOT, it is queued 
// up in the order of IDs passed to it.
// If you base BOTH retrieval systems on $i and $i++, they should all maintain 
// horizontal consistency

// Create an array from the CSV $idList and use that as the index value to sort 
// through both datastreams at once
$idListArray = explode(",",$idList);

$recordsArray = array();
for($index = 0; $index < (count($idListArray) - 1); $index++){
    // This Loop will allow us to go through each record and pull out what we 
    // want and we can store each processed record as part of an array that gets 
    // checked against DB and processed into MODS XML format.
    
 //****   // Store in the array the PDF URL String?
    
//    
// VARIABLES FROM EFETCH. coming from XML stream:
//

    // MedlineCitation Vars -- keep in mind that a loop structure has to be
    //added to iterate between multiple articles
//    $pmid = $eFetchXML->PubmedArticle[$index]->MedlineCitation->PMID->__toString();
    
    // direct variables - don't need to be processed really
    $issn = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISSN->__toString();
    $volume = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Volume->__toString();
    $issue = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->JournalIssue->Issue->__toString();
    $journalTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->Title->__toString();
    $journalAbrTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Journal->ISOAbbreviation->__toString();
    $articleTitle = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->ArticleTitle->__toString(); // This is a full title, inclusive of SubTitle. May have to explode out on Colon
    
    // array variables - returns an array, so we need to iterate and process
    // what we want from it
    
    $abstract = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->Abstract->AbstractText; // may return array to iterate for multiple paragraphs
    $authors = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList; // will return Array of authors. Contains Affiliation info as well, which is an object
    $grants = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->GrantList; // returns an array with objects containing GrantID, Acronym, Agency, Country
    $keywords = $eFetchXML->PubmedArticle[$index]->MedlineCitation->KeywordList->Keyword; // returns an array which can be iterated for all keywords #woot
//    $publicationType = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->PublicationTypeList->PublicationType; // may return an array? otherwise, just "JOURNAL ARTICLE"
//    $affiliationSample = $eFetchXML->PubmedArticle[$index]->MedlineCitation->Article->AuthorList->Author[0]->AffiliationInfo->Affiliation; // just a sample, testing double array within object chain, gonna have to build into the author loop
       
    
    // PubmedData chain has variables too, but mostly redundant and incomplete 
    // compared to the JSON variable
    $articleIds = $eFetchXML->PubmedArticle[$index]->PubmedData->ArticleIdList->ArticleId; // returns array of IDs keyed by number, so not helpful in extrapolating what the ID is. BUT, can iterate this and check for PMC####### and NIHMS###### rows

    // Not all articles have it, but there are some with Mesh arrays (Medical Subject Headings)
    $mesh = $eFetchXML->PubmedArticle[$index]->MedlineCitation->MeshHeadingList; // returns array with objects for elements
    
//
// VARIABLES FROM ESUMMARY, coming from JSON stream
//

    $uid = $json_eSum->result->uids[$index]; // Important Hook for rest of Variables in JSON Tree
    
    // direct variables to be passed to Records Array
    $sortTitle = $json_eSum->result->$uid->sorttitle;
    $pages = $json_eSum->result->$uid->pages;
    $essnESum = $json_eSum->result->$uid->essn;
    $sortPubDate = $json_eSum->result->$uid->sortpubdate;
    
    // array variables, we need to iterate and process
    $articleIdESum = $json_eSum->result->$uid->articleids; // returns an array
    
 //   $volumeESum = $json_eSum->result->$uid->volume; // Duplicate variable
 //   $issueESum = $json_eSum->result->$uid->issue; // Duplicate variable, ideal world would check both streams and take the non-empty one if any are empty
 //   $lang = $json_eSum->result->$uid->lang; // returns array
 //   $issnESum = $json_eSum->result->$uid->issn; // Duplicate variable
 //   $pubTypeESum = $json_eSum->result->$uid->pubtype; // returns an array
 //   $viewCount = $json_eSum->result->$uid->viewcount; // We don't need, but its cool to have


//
// PREPARE GRABBED DATA FOR PASSING TO RECORDS ARRAY
//

    // Abstract Parse
    // Whether text is contained in a single element or not, it will always return as an array.
    // The following code expects $abstract to be presented as already parsed to the array level
        unset($abstractString);
        for($i = 0; $i < count($abstract); $i++){
            // Add "new line" functionality? Otherwise multiple paragraphs wil just be combined
            $abstractString .= $abstract[$i]->__toString() ." ";
        } 

    // Author Parsing
    // Given an Author array with various sub-arrays. Goal is to prepare
    // a new Author Array which will be processed upon XML generation
    // This should return an Array full of Author Arrays following: FirstName, LastName, Fullname, Affiliation
    // Check in with Bryan about normalization of Author metadata in order to have it match the named authority files by Annie
        $authorArray = array();
        for ($i = 0; $i < count($authors->Author); $i++){
            $fname = $authors->Author[$i]->ForeName->__toString(); // Will return a string of Firstname + Middle Initial if given...
            $lname = $authors->Author[$i]->LastName->__toString();
            $fullname = $fname . " " . $lname;
            $authAffil = $authors->Author[$i]->AffiliationInfo->Affiliation->__toString(); // Test to see how many sample records have >1 Affil, but this will at least capture the first one listed

            $authorArray[$i] = array($fname,$lname,$fullname,$authAffil);
        }

    // Grant Number Parsing
    // Presents an iterative object full of "Grant" arrays
        unset($grantIDString);
        for ($i = 0; $i < count($grants->Grant); $i++){
            $grantIDString .= $grants->Grant[$i]->GrantID->__toString();
            if($i != (count($grants->Grant) - 1)){
                $grantIDString .= ", ";
            }
        }
    
    // Keyword Parsing
    // Presents a direct array ready for iteration
        unset($keywordString);
        for ($i = 0; $i < count($keywords); $i++){
            $keywordString .= ucfirst($keywords[$i]->__toString());  // to comply with first character UC ... <3 Bryan
            if($i != (count($keywords) -1)){
                $keywordString .= ", ";
            }
        }
    
    // ArticleID Parsing
    // When sent here, var will be an array of object-arrays
        $articleIdArray = array();
        for ($i = 0; $i < (count($articleIdESum) - 1); $i++){
            $idtype = $articleIdESum[$i]->idtype;
            $value = $articleIdESum[$i]->value;

            // Here is where we pick out which IDs we are interested in.
            // Any idtype not here will not be captured going forward
            if($idtype == "doi" || $idtype == "pmc" || $idtype == "mid" || $idtype == "rid" || $idtype == "eid" || $idtype == "pii"){
              $articleIdArray[$idtype] = array($value);
            }

        }
    
    // Article Title Parsing
    // Title variables are available from the XML stream and the JSON stream
    // Goal is to parse what we have returned into 
    // NonSort, sortTitle, startTitle, subTitle, fullTitle
    // and store that in a Title Array to be parsed for MODS generation
    // The following using the XML Stream "Article Title" as basis for everything else

        // Generate nonsort var

        $nonsorts = array("A","An","The");
        $title_array = explode(" ", $articleTitle);
        if (in_array($title_array[0], $nonsorts)){
            $nonsort = $title_array[0];
            $sortTitle = implode(" ", array_slice($title_array, 1)); // rejoins title array starting at first element
        } else {
            $nonsort = FALSE;
            $sortTitle = $articleTitle;
        }
    
        // Generate subTitle and startTitle from fullTitle string
        $subTitleArray = explode(": ",$articleTitle);
            // now $subTitleArray[0] will be startTitle & [1] will be subTitle
            $startTitle = $subTitleArray[0];
            $subTitle = $subTitleArray[1];
    
        // Combine it all into one master title array to be parsed for MODS Record
        $parsedTitleArray = array($nonsort,$sortTitle,$startTitle,$subTitle,$articleTitle);
    
    // Mesh Subject Heading Parsing
    // Put code here when developed
    
    //
    // Build sub-array structures with the various metadata variables for 
    // easier processing later, structured by the MODS top level elements
        
        $titleInfo;
        $name;
        $originInfo;
        $abstractMODS;
        $note;
        $subject;
        $relatedItem;
        $identifierMODS;
        $part; // ?
        $extension; // ?
        
        // also keep in mind for another section the static MODS elements that
        // will be the same across all records
        // typeOfResource; genre; language...etc?
        
        
    
    print $index;
    print "     ";
    print $uid;
    print "     ";
    print $pmid;
    print "<br>";
    
    $recordsArray[$uid] = array(); // pass processed stuff into here and it will be stored, keyed to the UID
    
}

// At some point, add interaction between the script and a file db of IDs to
// skip already-ingested objects

//
// DEV TEST
//
print "<h1>Results from eSummary</h1>";
print "<pre>";
print_r($json_eSum);
print "</pre>";


print "<h1>Results from eSearch</h1>";

print "<h2>Combined Search</h2>";
print "<pre>";
print_r($json_response);
print "</pre>";

print "<h1>Results from eFetch XML Load</h1>";
print "<pre>";
print_r($eFetchXML);
print "</pre>";
//

/* DEV GRAVEYARD
 *****file get contents timeout****
 * $ctx = stream_context_create(array(
    'http' => array(
        'timeout' => 60
        )
    ));
 
 *
 * 
 * 
 * ****Code to use to grab the PDF from the server*****

$pathPDF = "http://www.ncbi.nlm.nih.gov/pmc/articles/PMC4750400/pdf/nihms723722.pdf";

$PDF = file_get_contents($pathPDF) or die("Could not get file");

file_put_contents("test.pdf", $PDF);
 * 
 **** SEARCH STRINGS BROKEN UP ****
$search_FCRR = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=(((HD052120%5BGrant%20Number%5D)%20AND%20FCRR%5BAffiliation%5D))"; // Grant Number & "FCRR"
$search_FCRR_long = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant+Number%5D)%20AND%20Florida+Center+for+Reading+Research%5BAffiliation%5D)"; // Grant Number & "Florida Center for Reading Research"
$search_FSU_long= "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant%20Number%5D)%20AND%20Florida%20State%20University%5BAffiliation%5D)"; // Grant Number & "Florida State University"
$search_FSU = "http://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=1000&tool=FSU_IR&email=aretteen@fsu.edu&term=((HD052120%5BGrant+Number%5D)%20AND%20Florida+State+University%5BAffiliation%5D)"; // Grant Number & "FSU"

 * 
 * 
 */
?>
