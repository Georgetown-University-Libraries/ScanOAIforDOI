<?php

/*
Copyright (c) 2016, Georgetown University Bioethics Research Library
All rights reserved.

Written by Mark Hakkarinen, Head of Information Services, Kennedy Institute of Ethics

Redistribution and use in source and binary forms, with or without modification, 
are permitted provided that the following conditions are met:

Redistributions of source code must retain the above copyright notice, 
this list of conditions and the following disclaimer.
Redistributions in binary form must reproduce the above copyright notice, 
this list of conditions and the following disclaimer in the documentation 
and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" 
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES 
OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, 
OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, 
WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY 
WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

This software makes use of the OAI-PMH client/harvester library for PHP, available under a MIT License at https://github.com/caseyamcl/phpoaipmh see LICENSE file for details.
*/

/* Script Settings */

$crossref_api_auth='';//Crossref API registered email address, such as: yourname@youremail.com
$open_url_resolver_base='http://worldcatlibraries.org/registry/gateway';//used in OpenURL links, alternatives include a local resolver
$doi_resolver_base='http://dx.doi.org/';//alternatives include http://doai.io/
$include_openURL_in_metadata_update_file=1;//turn to 0 if you only want DOI resolvers and not OpenURL with a DOI query
$oai_interval=0;//sleep between OAI-PMH record pulls
$api_interval=0;//additional sleep pause for API queries to crossref (1 minimum recommended)
$skip_oai_with_doi=0;//change to 1 if you want to skip looking up records with a DOI already assigned
$records_to_check=10000;//need to add capability to resume batches
$mdPrefix='oai_dc';//this is the "lowest common denominator" schema for OAI-PMH (so maximum compatibility of metadata fields)
$output_DOI_prefix='1';//add doi: to identifier field before actual DOI
#setSpec='col_10822_503787';//optional set for selective harvesting
#$setSpec='com_10822_556072';//SFS-Q test
#$setSpec='col_10822_761738';//english
#$setSpec='col_10822_761726';//
$setSpec='col_10822_710480';//pellegrino

/* Load Library */
require_once __DIR__ . '/vendor/autoload.php';
$client = new \Phpoaipmh\Client('https://repository.library.georgetown.edu/oai/request');
$myEndpoint = new \Phpoaipmh\Endpoint($client);

/* Output Files */


$new_doi[]=array('handle','dc.title','dc.title.doi','dc.creator','dc.creator.doi','dc.identifier','dc.identifier.uri','crossref_url');//file with error checking columns
$new_doi_update[]=array('handle','dc.identifier','dc.identifier.uri');//file for handle and found metadata columns only
$update_all[]=array('id','dc.identifier','dc.identifier.uri');//file to re-write all identifier and identifier.uri metadata

function checkDOI($url){
	//queries API and checks for DOI field in XML and returns response		
	print "API query: $url\n";	
	$ch = curl_init(); 

	//basic parameters
	curl_setopt ($ch, CURLOPT_URL, $url); 
	curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1); 
	$html=curl_exec ($ch); 
	
	if (preg_match('/<doi>(.*)?<\/doi>/', $html)){
		$found=1;
	}else{
		$found=0;
	}
	
	return array($found,$html);
}

function encodeFunc($value) {
    return "\"$value\"";
}

function write_csv($filename,$array){
	$fp = fopen($filename, 'w');		
	echo "writing $filename\n\n";
			
	foreach ($array as $fields) {
		//a workaround to get quotes on everything
		fputcsv($fp, array_map(encodeFunc, $fields), ',', chr(0));
	}
	
	fclose($fp);
}


    /**
     * Create a record iterator
     *
     * @param  string         $verb           OAI Verb
     * @param  string         $metadataPrefix Required by OAI-PMH endpoint
     * @param  \DateTime|null $from           An optional 'from' date for selective harvesting
     * @param  \DateTime|null $until          An optional 'from' date for selective harvesting
     * @param  string         $set            An optional setSpec for selective harvesting
     *
     * @return RecordIterator
     */
	 

$recordIterator = $myEndpoint->listRecords($mdPrefix,'','',$setSpec);

$doi_count=0;
for ($i = 0; $i < $records_to_check; $i++) {
	//reset values and iterate OAI-PMH
	$identifier=$title=$author=$doi=$crossref_url=$qdata=$doi_found=$doi_html='';
    $rec = $recordIterator->next();
	
	if($rec){
		#var_dump($rec);

		//get identifier				
		$identifier = split('::',(string)$rec->xpath('/record/header/identifier')[0][0])[1];
		$rec->registerXPathNamespace('dc','http://purl.org/dc/elements/1.1/');			
		echo "$i >> analyzing OAI-PMH record $identifier:\n";

		//get metadata fields
		$title = (string)$rec->xpath('//dc:title')[0][0];
		$author = (string)$rec->xpath('//dc:creator')[0][0];

		//assuming doi: prefix for value in existing record dc.identifier field				
		$oai_doi = split('doi:',(string)$rec->xpath('//dc:identifier[contains(.,"doi:")]')[0][0])[1];
		$oai_handle = $rec->xpath('//dc:identifier[contains(.,"hdl.handle.net")]')[0][0];
		$oai_openurl = addslashes($rec->xpath('//dc:identifier[contains(.,"xr8el9yb8v.search.serialssolutions.com")]')[0][0]);
		#$id_guess = split('/',$identifier)[1] + 47;//best guess for DSpace ID

		//if no DOI or we already have a DOI and we want to not skip these records
		if ($oai_doi=='' || $oai_doi!='' && $skip_oai_with_doi==0){
			//check for required basic crossref api query parameters
			if ($author == ''){
				echo "missing author, skipping doi lookup\n\n";
			}else if ($title==''){
				echo "missing title, skipping doi lookup\n\n";
			}else{//all is well
				echo "looking up DOI for \"$title\" by $author\n\n";

				//construct crossref query
				$author_surname = split(',',$author)[0];//get first author surname... assuming LastName,Firstname
				$qdata =urlencode(rtrim($title, '.')).'|'.urlencode($author_surname).'||key|';
				$crossref_url = 'http://doi.crossref.org/servlet/query?usr='.$crossref_api_auth.'&pwd=&type=a&format=unixref&qdata='.$qdata;//title + author query

				//check crossref API for DOI in returned response
				list($doi_found,$doi_html)=checkDOI($crossref_url);
				sleep($api_interval);

				if ($doi_found==1){
					$doi_count++;
					
					//extract metadata from crossref for error checking
					//it's also possible to use this information to infill metadata in a repository
					$doi_xml=simplexml_load_string($doi_html);
					$doi_title=(string)$doi_xml->xpath('(//title)[last()]')[0][0];//last listed in case item is part of a component
					$doi_author_surname=(string)$doi_xml->xpath('(//person_name[@sequence="first"]/surname)[last()]')[0][0];//last first author surname
					$doi_author_given=(string)$doi_xml->xpath('(//person_name[@sequence="first"]/given_name)[last()]')[0][0];//last first author given name
					$doi=(string)$doi_xml->xpath('(//doi)[last()]')[0][0];//last listed in case item is part of a component
					
					//check for component (such as a book chapter, to help with error correction)
					#$doi_component=(string)$doi_xml->xpath('//component_number')[0][0];
					
					//combine to match record formats for easier error checking
					$doi_author=$doi_author_surname.', '.$doi_author_given;

					$doi_resolver_link=$doi_resolver_base.$doi;

					if ($oai_openurl==''){
						//no openurl in record already
						$openurl_resolver_link=$open_url_resolver_base.'?version=1.0&url_ver=Z39.88-2004&id='.$doi;
					}
					else{
						//retain existing metadata but add id
					$openurl_resolver_link=str_replace('http://xr8el9yb8v.search.serialssolutions.com',$open_url_resolver_base,$oai_openurl).'&id='.$doi;
					}
					
					if ($include_openURL_in_metadata_update_file==1){
						//add multiple with pipes
						$dc_identifier_uri=$oai_handle.'||'.$doi_resolver_link.'||'.$openurl_resolver_link;

					} else {
						$dc_identifier_uri=$oai_handle.'||'.$doi_resolver_link;
					}
												
					//store for output to csv later
					if($output_DOI_prefix){$doi='doi:'.$doi;}
					$new_doi[] = array($identifier,$title,$doi_title,$author,$doi_author,$doi,$dc_identifier_uri,$crossref_url);
					$new_doi_update[] = array($identifier,$doi,$dc_identifier_uri);							
					#$update_all[]=array($id_guess,$doi,$dc_identifier_uri);
					echo "found doi:$doi\n\n";
				}
				else{
					$openurl_resolver_link=str_replace('http://xr8el9yb8v.search.serialssolutions.com',$open_url_resolver_base,$oai_openurl);
					echo "no DOI found\n\n";
					if ($openurl_resolver_link!=''){
						$dc_identifier_uri=$oai_handle.'||'.$openurl_resolver_link;
						#$update_all[]=array($id_guess,$doi,$dc_identifier_uri);
					}
					else{
						//nothing to update in record as no openurl or new DOI
						//$dc_identifier_uri=$oai_handle;
					}
					
					
				}
				
				sleep($oai_interval);
			}
		}else{
			echo "doi: $oai_doi found in record for $title, skipping lookup per skip flag\n\n";
		}
	
    }
	else{

    	//no record found, assume we're at the end, exit the iterator
    	echo "\n\n..::Summary::..\n\nscanned all records in the set $setSpec";
    	$i--;//correct for total records scanned (last one returned null)
    	break;
    }
}
	
echo "\n\nfound $doi_count new DOI from $i records";
if ($setSpec!=''){ echo " in $setSpec set"; }
echo "\n\n";

/* Write Results */		
write_csv('OAI_scan_'.$setSpec.'_'.date("F-j-Y-g-i").'_doi_check.csv',$new_doi);
write_csv('OAI_scan_'.$setSpec.'_'.date("F-j-Y-g-i").'_doi_found.csv',$new_doi_update);
#write_csv('OAI_scan_'.$setSpec.'_'.date("F-j-Y-g-i").'_update_all.csv',$update_all);
		
?>
