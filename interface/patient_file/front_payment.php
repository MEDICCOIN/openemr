<?php
/**
 * Front payment gui.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2006-2016 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../globals.php");
require_once("$srcdir/acl.inc");
require_once("$srcdir/patient.inc");
require_once("$srcdir/billing.inc");
require_once("$srcdir/payment.inc.php");
require_once("$srcdir/forms.inc");
require_once("$srcdir/sl_eob.inc.php");
require_once("$srcdir/invoice_summary.inc.php");
require_once("../../custom/code_types.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/encounter_events.inc.php");

use OpenEMR\Core\Header;
use OpenEMR\Services\FacilityService;

$pid = $_REQUEST['hidden_patient_code'] > 0 ? $_REQUEST['hidden_patient_code'] : $pid;

$facilityService = new FacilityService();

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['opener']); ?>
    <?php
    // Format dollars for display.
    //
    function bucks($amount)
    {
        if ($amount) {
            $amount = oeFormatMoney($amount);
            return $amount;
        }

        return '';
    }

    function rawbucks($amount)
    {
        if ($amount) {
            $amount = sprintf("%.2f", $amount);
            return $amount;
        }

        return '';
    }

    // Display a row of data for an encounter.
    //
    $var_index = 0;
    function echoLine($iname, $date, $charges, $ptpaid, $inspaid, $duept, $encounter = 0, $copay = 0, $patcopay = 0)
    {
        global $var_index;
        $var_index++;
        $balance = bucks($charges - $ptpaid - $inspaid);
        $balance = (round($duept, 2) != 0) ? 0 : $balance;//if balance is due from patient, then insurance balance is displayed as zero
        $encounter = $encounter ? $encounter : '';
        echo " <tr id='tr_" . attr($var_index) . "' >\n";
        echo "  <td class='detail'>" . text(oeFormatShortDate($date)) . "</td>\n";
        echo "  <td class='detail' id='" . attr($date) . "' align='center'>" . htmlspecialchars($encounter, ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='td_charges_$var_index' >" . htmlspecialchars(bucks($charges), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='td_inspaid_$var_index' >" . htmlspecialchars(bucks($inspaid * -1), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='td_ptpaid_$var_index' >" . htmlspecialchars(bucks($ptpaid * -1), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='td_patient_copay_$var_index' >" . htmlspecialchars(bucks($patcopay), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='td_copay_$var_index' >" . htmlspecialchars(bucks($copay), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='balance_$var_index'>" . htmlspecialchars(bucks($balance), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='center' id='duept_$var_index'>" . htmlspecialchars(bucks(round($duept, 2) * 1), ENT_QUOTES) . "</td>\n";
        echo "  <td class='detail' align='right'><input type='text' name='" . attr($iname) . "'  id='paying_" . attr($var_index) . "' " .
            " value='" . '' . "' onchange='coloring();calctotal()'  autocomplete='off' " .
            "onkeyup='calctotal()'  style='width:50px'/></td>\n";
        echo " </tr>\n";
    }

    // We use this to put dashes, colons, etc. back into a timestamp.
    //
    function decorateString($fmt, $str)
    {
        $res = '';
        while ($fmt) {
            $fc = substr($fmt, 0, 1);
            $fmt = substr($fmt, 1);
            if ($fc == '.') {
                $res .= substr($str, 0, 1);
                $str = substr($str, 1);
            } else {
                $res .= $fc;
            }
        }

        return $res;
    }

    // Compute taxes from a tax rate string and a possibly taxable amount.
    //
    function calcTaxes($row, $amount)
    {
        $total = 0;
        if (empty($row['taxrates'])) {
            return $total;
        }

        $arates = explode(':', $row['taxrates']);
        if (empty($arates)) {
            return $total;
        }

        foreach ($arates as $value) {
            if (empty($value)) {
                continue;
            }

            $trow = sqlQuery("SELECT option_value FROM list_options WHERE " .
                "list_id = 'taxrate' AND option_id = ? AND activity = 1 LIMIT 1", array($value));
            if (empty($trow['option_value'])) {
                echo "<!-- Missing tax rate '" . text($value) . "'! -->\n";
                continue;
            }

            $tax = sprintf("%01.2f", $amount * $trow['option_value']);
            // echo "<!-- Rate = '$value', amount = '$amount', tax = '$tax' -->\n";
            $total += $tax;
        }

        return $total;
    }
    
    ### Begin Medic Coin Module ###
    if (!function_exists('cformat'))
    {
        function cformat($float) { // cformat = 'Crypto Format'
        	return sprintf('%0.8f', $float);
        }
    }
    ### End Medic Coin Module ###
    
    $now = time();
    $today = date('Y-m-d', $now);
    $timestamp = date('Y-m-d H:i:s', $now);


    // $patdata = getPatientData($pid, 'fname,lname,pubpid');

    $patdata = sqlQuery("SELECT " .
        "p.fname, p.mname, p.lname, p.pubpid,p.pid, i.copay " .
        "FROM patient_data AS p " .
        "LEFT OUTER JOIN insurance_data AS i ON " .
        "i.pid = p.pid AND i.type = 'primary' " .
        "WHERE p.pid = ? ORDER BY i.date DESC LIMIT 1", array($pid));

    $alertmsg = ''; // anything here pops up in an alert box

    ### End Medic Coin Module ###
    $fee_medicaddress = $GLOBALS['medic_address'];
    ### Begin Medic Coin Module ###

    // If the Save button was clicked...
    if ($_POST['form_save']) {
        $form_pid = $_POST['form_pid'];
        $form_method = trim($_POST['form_method']);
        $form_source = trim($_POST['form_source']);
        $patdata = getPatientData($form_pid, 'fname,mname,lname,pubpid');
        $NameNew = $patdata['fname'] . " " . $patdata['lname'] . " " . $patdata['mname'];

        ### Begin Medic Coin Module ###
        $medic_prepaytotal = floatval($_POST['form_prepayment_medic']);
        $medic_paytotal = floatval($_POST['form_paytotal_medic']);
        $medic_address = trim($_POST['fee_medicaddress']);
        $medic_txID = trim($_POST['txID']);
        $medic_usd = floatval($_POST['medic_usd']);
        /*
        ### End Medic Coin Module ###
        if ($_REQUEST['radio_type_of_payment'] == 'pre_payment') {
            $payment_id = idSqlStatement(
                "insert into ar_session set " .
                "payer_id = ?" .
                ", patient_id = ?" .
                ", user_id = ?" .
                ", closed = ?" .
                ", reference = ?" .
                ", check_date =  now() , deposit_date = now() " .
                ",  pay_total = ?" .
                ", payment_type = 'patient'" .
                ", description = ?" .
                ", adjustment_code = 'pre_payment'" .
                ", post_to_date = now() " .
                ", payment_method = ?",
                array(0, $form_pid, $_SESSION['authUserID'], 0, $form_source, $_REQUEST['form_prepayment'], $NameNew, $form_method)
            );

            frontPayment($form_pid, 0, $form_method, $form_source, $_REQUEST['form_prepayment'], 0, $timestamp);//insertion to 'payments' table.
        }
        ### Begin Medic Coin Module ###
        */
        if ($_REQUEST['radio_type_of_payment'] == 'pre_payment') {
            $payment_id = idSqlStatement(
                "insert into ar_session set " .
                "payer_id = ?" .
                ", patient_id = ?" .
                ", user_id = ?" .
                ", closed = ?" .
                ", reference = ?" .
                ", check_date =  now() , deposit_date = now() " .
                ",  pay_total = ?" .
                ", payment_type = 'patient'" .
                ", description = ?" .
                ", adjustment_code = 'pre_payment'" .
                ", post_to_date = now() " .
                ", payment_method = ?" .
                ", medic_paytotal = ?" .
                ", medic_address = ?" .
                ", medic_txID = ?",
                array(0, $form_pid, $_SESSION['authUserID'], 0, $form_source, $_REQUEST['form_prepayment'], $NameNew, $form_method, $medic_prepaytotal, $medic_address, $medic_txID)
            );

            frontPayment($form_pid, 0, $form_method, $form_source, $_REQUEST['form_prepayment'], 0, $timestamp, '', $medic_prepaytotal, 0, $medic_address, $medic_txID);//insertion to 'payments' table.
        }
        ### End Medic Coin Module ###
        
        if ($_POST['form_upay'] && $_REQUEST['radio_type_of_payment'] != 'pre_payment') {
            foreach ($_POST['form_upay'] as $enc => $payment) {
                if ($amount = 0 + $payment) {
                    $zero_enc = $enc;
                    if ($_REQUEST['radio_type_of_payment'] == 'invoice_balance') {
                        if (!$enc) {
                            $enc = calendar_arrived($form_pid);
                        }
                    } else {
                        if (!$enc) {
                            $enc = calendar_arrived($form_pid);
                        }
                    }

                    //----------------------------------------------------------------------------------------------------
                    //Fetching the existing code and modifier
                    $ResultSearchNew = sqlStatement(
                        "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key " .
                        "WHERE code_types.ct_fee=1 AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                        array($form_pid, $enc)
                    );
                    if ($RowSearch = sqlFetchArray($ResultSearchNew)) {
                        $Codetype = $RowSearch['code_type'];
                        $Code = $RowSearch['code'];
                        $Modifier = $RowSearch['modifier'];
                    } else {
                        $Codetype = '';
                        $Code = '';
                        $Modifier = '';
                    }
                    
                    ### Begin Medic Coin Module ###
                    $medic_amount = cformat($amount/$medic_usd);
                    ### End Medic Coin Module ###
                    
                    //----------------------------------------------------------------------------------------------------
                    if ($_REQUEST['radio_type_of_payment'] == 'copay') {//copay saving to ar_session and ar_activity tables
                        ### Begin Medic Coin Module ###
                        /*
                        ### End Medic Coin Module ###
                        $session_id = sqlInsert(
                            "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total," .
                            " global_amount,payment_type,description,patient_id,payment_method,adjustment_code,post_to_date) " .
                            " VALUES ('0',?,?,now(),now(),?,'','patient','COPAY',?,?,'patient_payment',now())",
                            array($_SESSION['authId'], $form_source, $amount, $form_pid, $form_method)
                        );
                        ### Begin Medic Coin Module ###
                        */
                        $session_id = sqlInsert(
                            "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total," .
                            " global_amount,payment_type,description,patient_id,payment_method,adjustment_code,post_to_date, medic_paytotal, medic_address, medic_txID) " .
                            " VALUES ('0',?,?,now(),now(),?,'','patient','COPAY',?,?,'patient_payment',now(),?,?,?)",
                            array($_SESSION['authId'], $form_source, $amount, $form_pid, $form_method, $medic_amount, $medic_address, $medic_txID)
                        );
                        ### End Medic Coin Module ###
                        
                        sqlBeginTrans();
                        $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                        ### Begin Medic Coin Module ###
                        /*
                        ### End Medic Coin Module ###
                        $insrt_id = sqlInsert(
                            "INSERT INTO ar_activity (pid,encounter,sequence_no,code_type,code,modifier,payer_type,post_time,post_user,session_id,pay_amount,account_code)" .
                            " VALUES (?,?,?,?,?,?,0,now(),?,?,?,'PCP')",
                            array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, $_SESSION['authId'], $session_id, $amount)
                        );
                        ### Begin Medic Coin Module ###
                        */
                        $insrt_id = sqlInsert(
                            "INSERT INTO ar_activity (pid,encounter,sequence_no,code_type,code,modifier,payer_type,post_time,post_user,session_id,pay_amount,account_code,medic_payamount,medic_address,medic_txID)" .
                            " VALUES (?,?,?,?,?,?,0,now(),?,?,?,'PCP',?,?,?)",
                            array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, $_SESSION['authId'], $session_id, $amount, $medic_amount, $medic_address, $medic_txID)
                        );
                        ### End Medic Coin Module ###
                        sqlCommitTrans();
                        
                        ### Begin Medic Coin Module ###
                        /*
                        ### End Medic Coin Module ###
                        frontPayment($form_pid, $enc, $form_method, $form_source, $amount, 0, $timestamp);//insertion to 'payments' table.
                        ### Begin Medic Coin Module ###
                        */
                        frontPayment($form_pid, $enc, $form_method, $form_source, $amount, 0, $timestamp, '', $medic_amount, 0, $medic_address, $medic_txID);//insertion to 'payments' table.
                        ### End Medic Coin Module ###
                    }

                    if ($_REQUEST['radio_type_of_payment'] == 'invoice_balance' || $_REQUEST['radio_type_of_payment'] == 'cash') {                //Payment by patient after insurance paid, cash patients similar to do not bill insurance in feesheet.
                        if ($_REQUEST['radio_type_of_payment'] == 'cash') {
                            sqlStatement(
                                "update form_encounter set last_level_closed=? where encounter=? and pid=? ",
                                array(4, $enc, $form_pid)
                            );
                            sqlStatement(
                                "update billing set billed=? where encounter=? and pid=?",
                                array(1, $enc, $form_pid)
                            );
                        }

                        $adjustment_code = 'patient_payment';
                        ### Begin Medic Coin Module ###
                        /*
                        ### End Medic Coin Module ###
                        $payment_id = idSqlStatement(
                            "insert into ar_session set " .
                            "payer_id = ?" .
                            ", patient_id = ?" .
                            ", user_id = ?" .
                            ", closed = ?" .
                            ", reference = ?" .
                            ", check_date =  now() , deposit_date = now() " .
                            ",  pay_total = ?" .
                            ", payment_type = 'patient'" .
                            ", description = ?" .
                            ", adjustment_code = ?" .
                            ", post_to_date = now() " .
                            ", payment_method = ?",
                            array(0, $form_pid, $_SESSION['authUserID'], 0, $form_source, $amount, $NameNew, $adjustment_code, $form_method)
                        );

                        //--------------------------------------------------------------------------------------------------------------------

                        frontPayment($form_pid, $enc, $form_method, $form_source, 0, $amount, $timestamp);//insertion to 'payments' table.
                        ### Begin Medic Coin Module ###
                        */
                        $payment_id = idSqlStatement(
                            "insert into ar_session set " .
                            "payer_id = ?" .
                            ", patient_id = ?" .
                            ", user_id = ?" .
                            ", closed = ?" .
                            ", reference = ?" .
                            ", check_date =  now() , deposit_date = now() " .
                            ",  pay_total = ?" .
                            ", payment_type = 'patient'" .
                            ", description = ?" .
                            ", adjustment_code = ?" .
                            ", post_to_date = now() " .
                            ", payment_method = ?" .
                            ", medic_paytotal = ?" .
                            ", medic_address = ?" .
                            ", medic_txID = ?",
                            array(0, $form_pid, $_SESSION['authUserID'], 0, $form_source, $amount, $NameNew, $adjustment_code, $form_method, $medic_amount, $medic_address, $medic_txID)
                        );

                        //--------------------------------------------------------------------------------------------------------------------

                        frontPayment($form_pid, $enc, $form_method, $form_source, 0, $amount, $timestamp, '', 0, $medic_amount, $medic_address, $medic_txID);//insertion to 'payments' table.
                        ### End Medic Coin Module ###
                        
                        //--------------------------------------------------------------------------------------------------------------------

                        $resMoneyGot = sqlStatement(
                            "SELECT sum(pay_amount) as PatientPay FROM ar_activity where pid =? and " .
                            "encounter =? and payer_type=0 and account_code='PCP'",
                            array($form_pid, $enc)
                        );//new fees screen copay gives account_code='PCP'
                        $rowMoneyGot = sqlFetchArray($resMoneyGot);
                        $Copay = $rowMoneyGot['PatientPay'];

                        //--------------------------------------------------------------------------------------------------------------------

                        //Looping the existing code and modifier
                        $ResultSearchNew = sqlStatement(
                            "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key WHERE code_types.ct_fee=1 " .
                            "AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                            array($form_pid, $enc)
                        );
                        while ($RowSearch = sqlFetchArray($ResultSearchNew)) {
                            $Codetype = $RowSearch['code_type'];
                            $Code = $RowSearch['code'];
                            $Modifier = $RowSearch['modifier'];
                            $Fee = $RowSearch['fee'];

                            $resMoneyGot = sqlStatement(
                                "SELECT sum(pay_amount) as MoneyGot FROM ar_activity where pid =? " .
                                "and code_type=? and code=? and modifier=? and encounter =? and !(payer_type=0 and account_code='PCP')",
                                array($form_pid, $Codetype, $Code, $Modifier, $enc)
                            );
                            //new fees screen copay gives account_code='PCP'
                            $rowMoneyGot = sqlFetchArray($resMoneyGot);
                            $MoneyGot = $rowMoneyGot['MoneyGot'];

                            $resMoneyAdjusted = sqlStatement(
                                "SELECT sum(adj_amount) as MoneyAdjusted FROM ar_activity where " .
                                "pid =? and code_type=? and code=? and modifier=? and encounter =?",
                                array($form_pid, $Codetype, $Code, $Modifier, $enc)
                            );
                            $rowMoneyAdjusted = sqlFetchArray($resMoneyAdjusted);
                            $MoneyAdjusted = $rowMoneyAdjusted['MoneyAdjusted'];

                            $Remainder = $Fee - $Copay - $MoneyGot - $MoneyAdjusted;
                            $Copay = 0;
                            if (round($Remainder, 2) != 0 && $amount != 0) {
                                if ($amount - $Remainder >= 0) {
                                    $insert_value = $Remainder;
                                    $amount = $amount - $Remainder;
                                    
                                } else {
                                    $insert_value = $amount;
                                    $amount = 0;
                                }
                                ### Begin Medic Coin Module ###
                                $medic_insert_value = cformat($insert_value/$medic_usd);
                                $medic_amount = cformat($medic_amount/$medic_usd);
                                ### End Medic Coin Module ###
                                sqlBeginTrans();
                                $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                                ### Begin Medic Coin Module ###
                                /*
                                ### End Medic Coin Module ###
                                sqlStatement(
                                    "insert into ar_activity set " .
                                    "pid = ?" .
                                    ", encounter = ?" .
                                    ", sequence_no = ?" .
                                    ", code_type = ?" .
                                    ", code = ?" .
                                    ", modifier = ?" .
                                    ", payer_type = ?" .
                                    ", post_time = now() " .
                                    ", post_user = ?" .
                                    ", session_id = ?" .
                                    ", pay_amount = ?" .
                                    ", adj_amount = ?" .
                                    ", account_code = 'PP'",
                                    array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, 0, $_SESSION['authUserID'], $payment_id, $insert_value, 0)
                                );
                                ### Begin Medic Coin Module ###
                                */
                                sqlStatement(
                                    "insert into ar_activity set " .
                                    "pid = ?" .
                                    ", encounter = ?" .
                                    ", sequence_no = ?" .
                                    ", code_type = ?" .
                                    ", code = ?" .
                                    ", modifier = ?" .
                                    ", payer_type = ?" .
                                    ", post_time = now() " .
                                    ", post_user = ?" .
                                    ", session_id = ?" .
                                    ", pay_amount = ?" .
                                    ", adj_amount = ?" .
                                    ", account_code = 'PP'" .
                                    ", medic_payamount = ?" .
                                    ", medic_adjamount = ?" .
                                    ", medic_address = ?" .
                                    ", medic_txID = ?" ,
                                    array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, 0, $_SESSION['authUserID'], $payment_id, $insert_value, 0, $medic_insert_value, 0, $medic_address, $medic_txID)
                                );
                                ### End Medic Coin Module ###
                                sqlCommitTrans();
                            }//if
                        }//while
                        if ($amount != 0) {//if any excess is there.
                            sqlBeginTrans();
                            $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                            ### Begin Medic Coin Module ###
                            /*
                            ### End Medic Coin Module ###
                            sqlStatement(
                                "insert into ar_activity set " .
                                "pid = ?" .
                                ", encounter = ?" .
                                ", sequence_no = ?" .
                                ", code_type = ?" .
                                ", code = ?" .
                                ", modifier = ?" .
                                ", payer_type = ?" .
                                ", post_time = now() " .
                                ", post_user = ?" .
                                ", session_id = ?" .
                                ", pay_amount = ?" .
                                ", adj_amount = ?" .
                                ", account_code = 'PP'",
                                array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, 0, $_SESSION['authUserID'], $payment_id, $amount, 0)
                            );
                            ### Begin Medic Coin Module ###
                            */
                            sqlStatement(
                                "insert into ar_activity set " .
                                "pid = ?" .
                                ", encounter = ?" .
                                ", sequence_no = ?" .
                                ", code_type = ?" .
                                ", code = ?" .
                                ", modifier = ?" .
                                ", payer_type = ?" .
                                ", post_time = now() " .
                                ", post_user = ?" .
                                ", session_id = ?" .
                                ", pay_amount = ?" .
                                ", adj_amount = ?" .
                                ", account_code = 'PP'" .
                                ", medic_payamount = ?" .
                                ", medic_adjamount = ?" .
                                ", medic_address = ?" .
                                ", medic_txID = ?" ,
                                array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier, 0, $_SESSION['authUserID'], $payment_id, $amount, 0, $medic_amount, 0, $medic_address, $medic_txID)
                            );
                            ### End Medic Coin Module ###
                            sqlCommitTrans();
                        }

                        //--------------------------------------------------------------------------------------------------------------------
                    }//invoice_balance
                }//if ($amount = 0 + $payment)
            }//foreach
        }//if ($_POST['form_upay'])
    }//if ($_POST['form_save'])

    if ($_POST['form_save'] || $_REQUEST['receipt']) {
        if ($_REQUEST['receipt']) {
            $form_pid = $_GET['patient'];
            $timestamp = decorateString('....-..-.. ..:..:..', $_GET['time']);
        }

    // Get details for what we guess is the primary facility.
        $frow = $facilityService->getPrimaryBusinessEntity(array("useLegacyImplementation" => true));

    // Get the patient's name and chart number.
        $patdata = getPatientData($form_pid, 'fname,mname,lname,pubpid');

    // Re-fetch payment info.
        
        $payrow = sqlQuery("SELECT " .
        "SUM(amount1) AS amount1, " .
        "SUM(amount2) AS amount2, " .
        ### Begin Medic Coin Module ###
        "SUM(medic_amount1) AS medic_amount1, " .
        "SUM(medic_amount2) AS medic_amount2, " .
        "MAX(medic_address) AS medic_address, " .
        "MAX(medic_txID) AS medic_txID, " .
        ### End Medic Coin Module ###
        "MAX(method) AS method, " .
        "MAX(source) AS source, " .
        "MAX(dtime) AS dtime, " .
        // "MAX(user) AS user " .
        "MAX(user) AS user, " .
        "MAX(encounter) as encounter " .
        "FROM payments WHERE " .
        "pid = ? AND dtime = ?", array($form_pid, $timestamp));

    // Create key for deleting, just in case.
        $ref_id = ($_REQUEST['radio_type_of_payment'] == 'copay') ? $session_id : $payment_id;
        $payment_key = $form_pid . '.' . preg_replace('/[^0-9]/', '', $timestamp) . '.' . $ref_id;

        if ($_REQUEST['radio_type_of_payment'] != 'pre_payment') {
            // get facility from encounter
            $tmprow = sqlQuery("SELECT `facility_id` FROM `form_encounter` WHERE `encounter` = ?", array($payrow['encounter']));
            $frow = $facilityService->getById($tmprow['facility_id']);
        } else {
            // if pre_payment, then no encounter yet, so get main office address
            $frow = $facilityService->getPrimaryBillingLocation();
        }

    // Now proceed with printing the receipt.
    ?>

    <title><?php echo xlt('Receipt for Payment'); ?></title>

    <?php Header::setupHeader(['jquery-ui']); ?>

    <script language="JavaScript">
        
        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        $(document).ready(function () {
            var win = top.printLogSetup ? top : opener.top;
            win.printLogSetup(document.getElementById('printbutton'));
        });

        function closeHow(e) {
            if (top.tab_mode) {
                top.activateTabByName('pat', true);
                top.tabCloseByName(window.name);
            } else {
                if (opener) {
                    if (opener.name === "left_nav") {
                        dlgclose();
                        return;
                    }
                }
                window.history.back();
            }
        }

        // This is action to take before printing and is called from restoreSession.php.
        function printlog_before_print() {
            let divstyle = document.getElementById('hideonprint').style;
            divstyle.display = 'none';
            // currently exit is not hidden by default in case receipt print is not needed
            // and left here for future option to force users to print via global etc..
            // can still print later via reports.
            divstyle = document.getElementById('showonprint').style;
            divstyle.display = '';
        }

        // Process click on Delete button.
        function deleteme() {
            dlgopen('deleter.php?payment=<?php echo attr($payment_key); ?>', '_blank', 500, 450);
            return false;
        }

        // Called by the deleteme.php window on a successful delete.
        function imdeleted() {
            if (opener) {
                dlgclose(); // we're in reports/leftnav and callback reloads.
            } else {
                window.history.back(); // this is us full screen.
            }
        }

        // Called to switch to the specified encounter having the specified DOS.
        // This also closes the popup window.
        function toencounter(enc, datestr, topframe) {
            topframe.restoreSession();
            // Hard-coding of RBot for this purpose is awkward, but since this is a
            // pop-up and our openemr is left_nav, we have no good clue as to whether
            // the top frame is more appropriate.
            if(!top.tab_mode) {
                topframe.left_nav.forceDual();
                topframe.left_nav.setEncounter(datestr, enc, '');
                topframe.left_nav.loadFrame('enc2', 'RBot', 'patient_file/encounter/encounter_top.php?set_encounter=' + enc);
            } else {
                top.goToEncounter(enc);
            }
            if (opener) dlgclose();
        }

    </script>
</head>
<body bgcolor='#ffffff'>
<center>

    <p>
    <h2><?php echo xlt('Receipt for Payment'); ?></h2>

    <p><?php echo text($frow['name']) ?>
        <br><?php echo text($frow['street']) ?>
        <br><?php echo text($frow['city'] . ', ' . $frow['state']) . ' ' .
            text($frow['postal_code']) ?>
        <br><?php echo text($frow['phone']) ?>

    <p>
    <table border='0' cellspacing='8'>
        <tr>
            <td><?php echo xlt('Date'); ?>:</td>
            <td><?php echo text(oeFormatSDFT(strtotime($payrow['dtime']))) ?></td>
        </tr>
        <tr>
            <td><?php echo xlt('Patient'); ?>:</td>
            <td><?php echo text($patdata['fname']) . " " . text($patdata['mname']) . " " .
                    text($patdata['lname']) . " (" . text($patdata['pubpid']) . ")" ?></td>
        </tr>
        <tr>
            <td><?php echo xlt('Paid Via'); ?>:</td>
            <td><?php echo generate_display_field(array('data_type' => '1', 'list_id' => 'payment_method'), $payrow['method']); ?></td>
        </tr>
        <tr>
            <td><?php echo xlt('Check/Ref Number'); ?>:</td>
            <td><?php echo text($payrow['source']) ?></td>
        </tr>
        <tr>
            <td><?php echo xlt('Amount for This Visit'); ?>:</td>
            <td><?php
                    ### Begin Medic Coin Module ###
                    /*
                    ### End Medic Coin Module ###
                    echo text(oeFormatMoney($payrow['amount1']))
                    ### Begin Medic Coin Module ###
                    */
                    echo (text(oeFormatMoney($payrow['amount1'])) . ($_REQUEST['form_method'] == 'mediccoin' ? text(' = ' . $payrow['medic_amount1'] . ' Medic') : ''));
                    ### End Medic Coin Module ###
                ?>
            </td>
        </tr>
        <tr>
            <td>
                <?php
                if ($_REQUEST['radio_type_of_payment'] == 'pre_payment') {
                    echo xlt('Pre-payment Amount');
                } else {
                    echo xlt('Amount for Past Balance');
                }
                ?>
                :
            </td>
            <td><?php 
                    ### Begin Medic Coin Module ###
                    /*
                    ### End Medic Coin Module ###
                    echo text(oeFormatMoney($payrow['amount2']))
                    ### Begin Medic Coin Module ###
                    */
                    echo (text(oeFormatMoney($payrow['amount2'])) . ($_REQUEST['form_method'] == 'mediccoin' ? text(' = ' . $payrow['medic_amount2'] . ' Medic') : ''));
                    ### End Medic Coin Module ###
                 ?>
            </td>
        </tr>
        <!-- Begin Medic Coin Module -->
        <?php
            if ($_REQUEST['form_method'] == 'mediccoin')
            {
                echo '<tr>';
                echo '<td>' . xlt('Medic Coin Receive Address') . ':</td>';
                echo '<td>' . text($payrow['medic_address']) . '</td>';
                echo '</tr>';
                
                echo '<tr>';
                echo '<td>' . xlt('QR code') . ':</td>';
                echo '<td><img src="' . $GLOBALS['medic_qrcode_prefix'] . $payrow['medic_address'] . '"></td>';
                echo '</tr>';

                echo '<tr>';
                echo '<td>' . xlt('Medic TxID') . ':</td>';
                echo '<td>' . text($payrow['medic_txID']) . '</td>';
                echo '</tr>';
            }
        ?>
        <!-- End Medic Coin Module -->
        <tr>
            <td><?php echo xlt('Received By'); ?>:</td>
            <td><?php echo text($payrow['user']) ?></td>
        </tr>
    </table>

    <div id='hideonprint'>
        <p>
            <input type='button' value='<?php echo xla('Print'); ?>' id='printbutton'/>

            <?php
            $todaysenc = todaysEncounterIf($pid);
            if ($todaysenc && $todaysenc != $encounter) {
                echo "&nbsp;<input type='button' " .
                    "value='" . xla('Open Today`s Visit') . "' " .
                    "onclick='toencounter($todaysenc, \"$today\", (opener ? opener.top : top))' />\n";
            }
            ?>

            <?php if (acl_check('admin', 'super')) { ?>
                <input type='button' value='<?php echo xla('Delete'); ?>' style='color:red' onclick='deleteme()'/>
            <?php } ?>
    </div>
    <div id='showonprint'>
        <input type='button' value='<?php echo xla('Exit'); ?>' id='donebutton' onclick="closeHow(event)"/>
    </div>
</center>
</body>

<?php
//
// End of receipt printing logic.
//
    } else {
        //
        // Here we display the form for data entry.
        //
        ?>
        <title><?php echo xlt('Record Payment'); ?></title>

    <style type="text/css">
        body {
            font-family: sans-serif;
            font-size: 10pt;
            font-weight: normal
        }

        .dehead {
            color: #000000;
            font-family: sans-serif;
            font-size: 10pt;
            font-weight: bold
        }

        .detail {
            color: #000000;
            font-family: sans-serif;
            font-size: 10pt;
            font-weight: normal
        }

        #ajax_div_patient {
            position: absolute;
            z-index: 10;
            background-color: #FBFDD0;
            border: 1px solid #ccc;
            padding: 10px;
        }

        .table.top_table > tbody > tr > td {
            border-top: 0;
            padding: 0;
        }
    </style>
    <!--Removed standard dependencies 12/29/17 as not needed any longer since moved to a tab/frame not popup.-->

    <script language='JavaScript'>
        var mypcc = '1';
    </script>
    <?php include_once("{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"); ?>
    <script language="javascript" type="text/javascript">
        document.onclick = HideTheAjaxDivs;
    </script>

    <script type="text/javascript" src="../../library/topdialog.js"></script>

    <script language="JavaScript">
        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        /* ### Begin Medic Coin Module ### */
        var medic_usd = <?php echo $GLOBALS['medic_usd'];?>;
        $(document).ready(function(){
            if ($('#check_number').length)
            {
                if ($('#form_method').val() == 'check_payment' || $('#form_method').val() == 'bank_draft')
                {
                    $('#check_number').prop('disabled', false);
                }
                else
                {
                    $('#check_number').prop('disabled', true);
                }
            }
            if ($('#form_method').val() == 'mediccoin')
            {
                $('#paytotal_medic, .medic_payment_field').show();
            }
            else
            {
                $('#paytotal_medic, .medic_payment_field').hide();
            }
            $('#fee_medicaddress_copy').on('click', function(){
                $('#fee_medicaddress').select();
                document.execCommand('copy');
                return false;
            });
        });
        function update_prepayment_medic(obj)
        {
            $('#form_prepayment_medic').val(Number($(obj).val()/medic_usd).toFixed(8));
        }
        /* ### Begin Medic Coin Module ### */

        function closeHow(e) {
            if (top.tab_mode) {
                top.activateTabByName('pat', true);
                top.tabCloseByName(window.name);
            } else {
                if (opener) {
                    if (opener.name === "left_nav") {
                        dlgclose();
                        return;
                    }
                }
                window.history.back();
            }
        }

        function calctotal() {
            var f = document.forms[0];
            var total = 0;
            for (var i = 0; i < f.elements.length; ++i) {
                var elem = f.elements[i];
                var ename = elem.name;
                if (ename.indexOf('form_upay[') == 0 || ename.indexOf('form_bpay[') == 0) {
                    if (elem.value.length > 0) total += Number(elem.value);
                }
            }
            f.form_paytotal.value = Number(total).toFixed(2);
            /* ### Begin Medic Coin Module ### */
            f.form_paytotal_medic.value = Number(total/medic_usd).toFixed(8);
            /* ### End Medic Coin Module ### */
            return true;
        }

        function coloring() {
            for (var i = 1; ; ++i) {
                if (document.getElementById('paying_' + i)) {
                    paying = document.getElementById('paying_' + i).value * 1;
                    patient_balance = document.getElementById('duept_' + i).innerHTML * 1;
                    //balance=document.getElementById('balance_'+i).innerHTML*1;
                    if (patient_balance > 0 && paying > 0) {
                        if (paying > patient_balance) {
                            document.getElementById('paying_' + i).style.background = '#FF0000';
                        }
                        else if (paying < patient_balance) {
                            document.getElementById('paying_' + i).style.background = '#99CC00';
                        }
                        else if (paying == patient_balance) {
                            document.getElementById('paying_' + i).style.background = '#ffffff';
                        }
                    }
                    else {
                        document.getElementById('paying_' + i).style.background = '#ffffff';
                    }
                }
                else {
                    break;
                }
            }
        }

        function CheckVisible(MakeBlank) {//Displays and hides the check number text box.
            if (document.getElementById('form_method').options[document.getElementById('form_method').selectedIndex].value == 'check_payment' ||
                document.getElementById('form_method').options[document.getElementById('form_method').selectedIndex].value == 'bank_draft') {
                document.getElementById('check_number').disabled = false;
            }
            else {
                document.getElementById('check_number').disabled = true;
            }
            /* ### Begin Medic Coin Module */
            if ($('#form_method').val() == 'mediccoin')
            {
                $('#paytotal_medic, .medic_payment_field').show();
            }
            else
            {
                $('#paytotal_medic, .medic_payment_field').hide();
            }
           /* ### End Medic Coin Module */
        }

        function validate() {
            var f = document.forms[0];
            ok = -1;
            top.restoreSession();
            issue = 'no';
            // prevent an empty form submission
            let flgempty = true;
            for (let i = 0; i < f.elements.length; ++i) {
                let ename = f.elements[i].name;
                if (f.elements[i].value == 'pre_payment' && f.elements[i].checked === true) {
                    if (Number(f.elements.namedItem("form_prepayment").value) !== 0) {
                        flgempty = false;
                    }
                    break;
                }
                if (ename.indexOf('form_upay[') === 0 || ename.indexOf('form_bpay[') === 0) {
                    if (Number(f.elements[i].value) !== 0) flgempty = false;
                }
            }
            if (flgempty) {
                alert("<?php echo xls('A Payment is Required!. Please input a payment line item entry.') ?>");
                return false;
            }
            // continue validation.
            if (((document.getElementById('form_method').options[document.getElementById('form_method').selectedIndex].value == 'check_payment' ||
                    document.getElementById('form_method').options[document.getElementById('form_method').selectedIndex].value == 'bank_draft') &&
                    document.getElementById('check_number').value == '')) {
                alert("<?php echo addslashes(xl('Please Fill the Check/Ref Number')) ?>");
                document.getElementById('check_number').focus();
                return false;
            }

            if (document.getElementById('radio_type_of_payment_self1').checked == false &&
                document.getElementById('radio_type_of_payment1').checked == false &&
                document.getElementById('radio_type_of_payment2').checked == false &&
                document.getElementById('radio_type_of_payment4').checked == false) {
                alert("<?php echo addslashes(xl('Please Select Type Of Payment.')) ?>");
                return false;
            }

            if (document.getElementById('radio_type_of_payment_self1').checked == true ||
                document.getElementById('radio_type_of_payment1').checked == true) {
                for (var i = 0; i < f.elements.length; ++i) {
                    var elem = f.elements[i];
                    var ename = elem.name;
                    if (ename.indexOf('form_upay[0') == 0) //Today is this text box.
                    {
                        if (elem.value * 1 > 0) {//A warning message, if the amount is posted with out encounter.
                            if (confirm("<?php echo addslashes(xlt('If patient has appointment click OK to create encounter otherwise, cancel this and then create an encounter for today visit.')) ?>")) {
                                ok = 1;
                            }
                            else {
                                elem.focus();
                                return false;
                            }
                        }
                        break;
                    }
                }
            }

            if (document.getElementById('radio_type_of_payment1').checked == true)//CO-PAY
            {
                var total = 0;
                for (var i = 0; i < f.elements.length; ++i) {
                    var elem = f.elements[i];
                    var ename = elem.name;
                    if (ename.indexOf('form_upay[0') == 0) //Today is this text box.
                    {
                        if (f.form_paytotal.value * 1 != elem.value * 1)//Total CO-PAY is not posted against today
                        {//A warning message, if the amount is posted against an old encounter.
                            if (confirm("<?php echo addslashes(xl('You are posting against an old encounter?')) ?>")) {
                                ok = 1;
                            }
                            else {
                                elem.focus();
                                return false;
                            }
                        }
                        break;
                    }
                }
            }//Co Pay
            else if (document.getElementById('radio_type_of_payment2').checked == true)//Invoice Balance
            {
                for (var i = 0; i < f.elements.length; ++i) {
                    var elem = f.elements[i];
                    var ename = elem.name;
                    if (ename.indexOf('form_upay[0') == 0) {
                        if (elem.value * 1 > 0) {
                            alert("<?php echo addslashes(xl('Invoice Balance cannot be posted. No Encounter is created.')) ?>");
                            return false;
                        }
                        break;
                    }
                }
            }
            
            /* ### Begin Medic Coin Module */
            if ($('#form_method').val() == 'mediccoin')
            {
                if (!$('#txID').val())
                {
                    alert("<?php echo addslashes(xl('Please Enter TxID')) ?>");
                    $('#txID').focus();
                    return false;
                }
            }
            /* ### End Medic Coin Module */
            
            if (ok == -1) {
                if (confirm("<?php echo addslashes(xl('Would you like to save?')) ?>")) {
                    return true;
                }
                else {
                    return false;
                }
            }
        }

        function cursor_pointer() {//Point the cursor to the latest encounter(Today)
            var f = document.forms[0];
            var total = 0;
            for (var i = 0; i < f.elements.length; ++i) {
                var elem = f.elements[i];
                var ename = elem.name;
                if (ename.indexOf('form_upay[') == 0) {
                    elem.focus();
                    break;
                }
            }
        }

        //=====================================================
        function make_it_hide_enc_pay() {
            document.getElementById('td_head_insurance_payment').style.display = "none";
            document.getElementById('td_head_patient_co_pay').style.display = "none";
            document.getElementById('td_head_co_pay').style.display = "none";
            document.getElementById('td_head_insurance_balance').style.display = "none";
            for (var i = 1; ; ++i) {
                var td_inspaid_elem = document.getElementById('td_inspaid_' + i)
                var td_patient_copay_elem = document.getElementById('td_patient_copay_' + i)
                var td_copay_elem = document.getElementById('td_copay_' + i)
                var balance_elem = document.getElementById('balance_' + i)
                if (td_inspaid_elem) {
                    td_inspaid_elem.style.display = "none";
                    td_patient_copay_elem.style.display = "none";
                    td_copay_elem.style.display = "none";
                    balance_elem.style.display = "none";
                }
                else {
                    break;
                }
            }
            document.getElementById('td_total_4').style.display = "none";
            document.getElementById('td_total_7').style.display = "none";
            document.getElementById('td_total_8').style.display = "none";
            document.getElementById('td_total_6').style.display = "none";

            document.getElementById('table_display').width = "420px";
        }

        //=====================================================
        function make_visible() {
            document.getElementById('td_head_rep_doc').style.display = "";
            document.getElementById('td_head_description').style.display = "";
            document.getElementById('td_head_total_charge').style.display = "none";
            document.getElementById('td_head_insurance_payment').style.display = "none";
            document.getElementById('td_head_patient_payment').style.display = "none";
            document.getElementById('td_head_patient_co_pay').style.display = "none";
            document.getElementById('td_head_co_pay').style.display = "none";
            document.getElementById('td_head_insurance_balance').style.display = "none";
            document.getElementById('td_head_patient_balance').style.display = "none";
            for (var i = 1; ; ++i) {
                var td_charges_elem = document.getElementById('td_charges_' + i)
                var td_inspaid_elem = document.getElementById('td_inspaid_' + i)
                var td_ptpaid_elem = document.getElementById('td_ptpaid_' + i)
                var td_patient_copay_elem = document.getElementById('td_patient_copay_' + i)
                var td_copay_elem = document.getElementById('td_copay_' + i)
                var balance_elem = document.getElementById('balance_' + i)
                var duept_elem = document.getElementById('duept_' + i)
                if (td_charges_elem) {
                    td_charges_elem.style.display = "none";
                    td_inspaid_elem.style.display = "none";
                    td_ptpaid_elem.style.display = "none";
                    td_patient_copay_elem.style.display = "none";
                    td_copay_elem.style.display = "none";
                    balance_elem.style.display = "none";
                    duept_elem.style.display = "none";
                }
                else {
                    break;
                }
            }
            document.getElementById('td_total_7').style.display = "";
            document.getElementById('td_total_8').style.display = "";
            document.getElementById('td_total_1').style.display = "none";
            document.getElementById('td_total_2').style.display = "none";
            document.getElementById('td_total_3').style.display = "none";
            document.getElementById('td_total_4').style.display = "none";
            document.getElementById('td_total_5').style.display = "none";
            document.getElementById('td_total_6').style.display = "none";

            document.getElementById('table_display').width = "505px";
        }

        function make_it_hide() {
            document.getElementById('td_head_rep_doc').style.display = "none";
            document.getElementById('td_head_description').style.display = "none";
            document.getElementById('td_head_total_charge').style.display = "";
            document.getElementById('td_head_insurance_payment').style.display = "";
            document.getElementById('td_head_patient_payment').style.display = "";
            document.getElementById('td_head_patient_co_pay').style.display = "";
            document.getElementById('td_head_co_pay').style.display = "";
            document.getElementById('td_head_insurance_balance').style.display = "";
            document.getElementById('td_head_patient_balance').style.display = "";
            for (var i = 1; ; ++i) {
                var td_charges_elem = document.getElementById('td_charges_' + i)
                var td_inspaid_elem = document.getElementById('td_inspaid_' + i)
                var td_ptpaid_elem = document.getElementById('td_ptpaid_' + i)
                var td_patient_copay_elem = document.getElementById('td_patient_copay_' + i)
                var td_copay_elem = document.getElementById('td_copay_' + i)
                var balance_elem = document.getElementById('balance_' + i)
                var duept_elem = document.getElementById('duept_' + i)
                if (td_charges_elem) {
                    td_charges_elem.style.display = "";
                    td_inspaid_elem.style.display = "";
                    td_ptpaid_elem.style.display = "";
                    td_patient_copay_elem.style.display = "";
                    td_copay_elem.style.display = "";
                    balance_elem.style.display = "";
                    duept_elem.style.display = "";
                }
                else {
                    break;
                }
            }
            document.getElementById('td_total_1').style.display = "";
            document.getElementById('td_total_2').style.display = "";
            document.getElementById('td_total_3').style.display = "";
            document.getElementById('td_total_4').style.display = "";
            document.getElementById('td_total_5').style.display = "";
            document.getElementById('td_total_6').style.display = "";
            document.getElementById('td_total_7').style.display = "";
            document.getElementById('td_total_8').style.display = "";

            document.getElementById('table_display').width = "635px";
        }

        function make_visible_radio() {
            document.getElementById('tr_radio1').style.display = "";
            document.getElementById('tr_radio2').style.display = "none";
        }

        function make_hide_radio() {
            document.getElementById('tr_radio1').style.display = "none";
            document.getElementById('tr_radio2').style.display = "";
        }

        function make_visible_row() {
            document.getElementById('table_display').style.display = "";
            document.getElementById('table_display_prepayment').style.display = "none";
        }

        function make_hide_row() {
            document.getElementById('table_display').style.display = "none";
            document.getElementById('table_display_prepayment').style.display = "";
        }

        function make_self() {
            make_visible_row();
            make_it_hide();
            make_it_hide_enc_pay();
            document.getElementById('radio_type_of_payment_self1').checked = true;
            cursor_pointer();
        }

        function make_insurance() {
            make_visible_row();
            make_it_hide();
            cursor_pointer();
            document.getElementById('radio_type_of_payment1').checked = true;
        }
    </script>

    </head>

    <body class="body_top" onLoad="cursor_pointer();">
    <div class="container well well-sm">
        <div class="col-md-8 col-md-offset-2">
            <span class='text'>
                <h4 class="text-center"><?php echo htmlspecialchars(xl('Accept Payment for'), ENT_QUOTES); ?>
                    <?php echo htmlspecialchars($patdata['fname'], ENT_QUOTES) . " " .
                        htmlspecialchars($patdata['lname'], ENT_QUOTES) . " " . htmlspecialchars($patdata['mname'], ENT_QUOTES) . " (" . htmlspecialchars($patdata['pid'], ENT_QUOTES) . ")" ?>
                        <?php $NameNew = $patdata['fname'] . " " . $patdata['lname'] . " " . $patdata['mname']; ?></h4>
                </span>
                <form class="form-inline" method='post'
                      action='front_payment.php<?php echo ($payid) ? "?payid=" . attr($payid) : ""; ?>'
                      onsubmit='return validate();'>
                    <input type='hidden' name='form_pid' value='<?php echo attr($pid) ?>'/>

                    <div>
                        <table class="table table-condensed top_table">
                            <tr>
                                <td class='text'>
                                    <label><?php echo xlt('Payment Method'); ?>:</label>
                                </td>
                                <td colspan='2'>
                                    <select name="form_method" id="form_method" class="text form-control"
                                            onChange='CheckVisible("yes")'>
                                        <?php
                                        $query1112 = "SELECT * FROM list_options where list_id=?  ORDER BY seq, title ";
                                        $bres1112 = sqlStatement($query1112, array('payment_method'));
                                        while ($brow1112 = sqlFetchArray($bres1112)) {
                                            if ($brow1112['option_id'] == 'electronic' || $brow1112['option_id'] == 'bank_draft') {
                                                continue;
                                            }
                                            echo "<option value='" . htmlspecialchars($brow1112['option_id'], ENT_QUOTES) . "'>" . htmlspecialchars(xl_list_label($brow1112['title']), ENT_QUOTES) . "</option>";
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <!-- Begin Medic Coin Module -->
                            <tr class="medic_payment_field">
                                <td class='text'>
                                    <label><?php echo xla('Medic Coin Receive Address'); ?>:</label>
                                </td>
                                <td colspan='2'>
                                    <input class="form-control" type='text' name='fee_medicaddress' id='fee_medicaddress' value='<?php echo $fee_medicaddress;?>' style='display:inline-block;width: 85%;' readonly />
                                    <a role="button" id="fee_medicaddress_copy" class="btn btn-default" title="Copy" style="display: inline-block;float:right;"><i class="fa fa-copy"></i></a>
                                    <p style="text-align: left;margin-top: 10px;"><img src="<?php echo ($GLOBALS['medic_qrcode_prefix'].$fee_medicaddress);?>" /></p>
                                </td>
                            </tr>
                            <tr class="medic_payment_field">
                                <td class='text'>
                                    <label><?php echo xla('TxID'); ?>:</label>
                                </td>
                                <td colspan='2'>
                                    <input name="txID" id="txID" type="text" class="form-control" title="<?php echo xla('txID'); ?>" value="" style="width: 100%;" />
                                </td>
                            </tr>
                            <!-- End Medic Coin Module -->
                            <tr height="5">
                                <td colspan='3'></td>
                            </tr>

                            <tr>
                                <td class='text'>
                                    <label><?php echo xla('Check/Ref Number'); ?>:</label>
                                </td>
                                <td colspan='2'>
                                    <div id="ajax_div_patient" style="display:none;"></div>
                                    <input class="input-sm" type='text' id="check_number" name='form_source'
                                       style="width:120px"
                                       value='<?php echo htmlspecialchars($payrow['source'], ENT_QUOTES); ?>'>
                                </td>
                            </tr>
                            <tr height="5">
                                <td colspan='3'></td>
                            </tr>

                            <tr>
                                <td class='text' valign="middle">
                                    <label><?php echo htmlspecialchars(xl('Patient Coverage'), ENT_QUOTES); ?>:</label>
                                </td>
                                <td class='text' colspan="2">
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_coverage" id="radio_type_of_coverage1"
                                           value="self"
                                           onClick="make_visible_radio();make_self();"/><?php echo htmlspecialchars(xl('Self'), ENT_QUOTES); ?>
                                    </label>
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_coverage" id="radio_type_of_coverag2"
                                           value="insurance"
                                           checked="checked"
                                           onClick="make_hide_radio();make_insurance();"/><?php echo htmlspecialchars(xl('Insurance'), ENT_QUOTES); ?>
                                    </label>
                                </td>
                            </tr>

                            <tr height="5">
                                <td colspan='3'></td>
                            </tr>

                            <tr id="tr_radio1" style="display:none"><!-- For radio Insurance -->
                                <td class='text' valign="top">
                                    <label><?php echo htmlspecialchars(xl('Payment against'), ENT_QUOTES); ?>:</label>
                                </td>
                                <td class='text' colspan="2">
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_payment" id="radio_type_of_payment_self1"
                                           value="cash"
                                           onClick="make_visible_row();make_it_hide_enc_pay();cursor_pointer();"/><?php echo htmlspecialchars(xl('Encounter Payment'), ENT_QUOTES); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr id="tr_radio2"><!-- For radio self -->
                                <td class='text' valign="top">
                                    <label><?php echo htmlspecialchars(xl('Payment against'), ENT_QUOTES); ?>:</label>
                                </td>
                                <td class='text' colspan="2">
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_payment"
                                           id="radio_type_of_payment1" value="copay" checked="checked"
                                           onClick="make_visible_row();cursor_pointer();"/><?php echo htmlspecialchars(xl('Co Pay'), ENT_QUOTES); ?>
                                    </label>
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_payment" id="radio_type_of_payment2"
                                           value="invoice_balance"
                                           onClick="make_visible_row();"/><?php echo htmlspecialchars(xl('Invoice Balance'), ENT_QUOTES); ?>
                                    </label>
                                    <label class="radio-inline">
                                    <input type="radio" name="radio_type_of_payment" id="radio_type_of_payment4"
                                           value="pre_payment"
                                           onClick="make_hide_row();"/><?php echo htmlspecialchars(xl('Pre Pay'), ENT_QUOTES); ?>
                                    </label>
                                </td>
                            </tr>

                            <tr height="15">
                                <td colspan='3'></td>
                            </tr>

                        </table>
                        <table width="200" border="0" cellspacing="0" cellpadding="0" id="table_display_prepayment"
                               style="display:none">
                            <tr>
                                <td class='detail'><?php echo htmlspecialchars(xl('Pre Payment'), ENT_QUOTES); ?></td>
                                <td><input type='text' name='form_prepayment' style='width:100px' onkeyup="update_prepayment_medic(this);" /></td>
                            </tr>
                            <!-- Begin Medic Coin Module -->
                            <tr id="paytotal_prepayment_medic">
                                <td class='detail'><?php echo htmlspecialchars(xl('Total in MEDIC'), ENT_QUOTES); ?></td>
                                <td><input class="text form-control" type='text' name='form_prepayment_medic' id='form_prepayment_medic' style='width:110px' readonly /></td>
                            </tr>
                            <!-- End Medic Coin Module -->
                        </table>
                    </div>
                    <table class="table table-condensed" id="table_display">
                        <thead>
                        <tr bgcolor="#cccccc" id="tr_head">
                            <th class="dehead">
                                <?php echo htmlspecialchars(xl('DOS'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead">
                                <?php echo htmlspecialchars(xl('Encounter'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_total_charge">
                                <?php echo htmlspecialchars(xl('Total Charge'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_rep_doc" style='display:none'>
                                <?php echo htmlspecialchars(xl('Report/ Form'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_description" style='display:none'>
                                <?php echo htmlspecialchars(xl('Description'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_insurance_payment">
                                <?php echo htmlspecialchars(xl('Insurance Payment'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_patient_payment">
                                <?php echo htmlspecialchars(xl('Patient Payment'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_patient_co_pay">
                                <?php echo htmlspecialchars(xl('Co Pay Paid'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_co_pay">
                                <?php echo htmlspecialchars(xl('Required Co Pay'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_insurance_balance">
                                <?php echo htmlspecialchars(xl('Insurance Balance'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center" id="td_head_patient_balance">
                                <?php echo htmlspecialchars(xl('Patient Balance'), ENT_QUOTES) ?>
                            </th>
                            <th class="dehead" align="center">
                                <?php echo htmlspecialchars(xl('Paying'), ENT_QUOTES) ?>
                            </th>
                        </tr>
                        </thead>
                        <?php
                        $encs = array();

                        // Get the unbilled service charges and payments by encounter for this patient.
                        //
                        $query = "SELECT fe.encounter, b.code_type, b.code, b.modifier, b.fee, " .
                        "LEFT(fe.date, 10) AS encdate ,fe.last_level_closed " .
                        "FROM  form_encounter AS fe left join billing AS b  on " .
                        "b.pid = ? AND b.activity = 1  AND " .//AND b.billed = 0
                        "b.code_type != 'TAX' AND b.fee != 0 " .
                        "AND fe.pid = b.pid AND fe.encounter = b.encounter " .
                        "where fe.pid = ? " .
                        "ORDER BY b.encounter";
                        $bres = sqlStatement($query, array($pid, $pid));
                        //
                        while ($brow = sqlFetchArray($bres)) {
                            $key = 0 - $brow['encounter'];
                            if (empty($encs[$key])) {
                                $encs[$key] = array(
                                'encounter' => $brow['encounter'],
                                'date' => $brow['encdate'],
                                'last_level_closed' => $brow['last_level_closed'],
                                'charges' => 0,
                                'payments' => 0);
                            }

                            if ($brow['code_type'] === 'COPAY') {
                                //$encs[$key]['payments'] -= $brow['fee'];
                            } else {
                                $encs[$key]['charges'] += $brow['fee'];
                                // Add taxes.
                                $sql_array = array();
                                $query = "SELECT taxrates FROM codes WHERE " .
                                "code_type = ? AND " .
                                "code = ? AND ";
                                array_push($sql_array, $code_types[$brow['code_type']]['id'], $brow['code']);
                                if ($brow['modifier']) {
                                    $query .= "modifier = ?";
                                    array_push($sql_array, $brow['modifier']);
                                } else {
                                    $query .= "(modifier IS NULL OR modifier = '')";
                                }

                                $query .= " LIMIT 1";
                                $trow = sqlQuery($query, $sql_array);
                                $encs[$key]['charges'] += calcTaxes($trow, $brow['fee']);
                            }
                        }

                        // Do the same for unbilled product sales.
                        //
                        $query = "SELECT fe.encounter, s.drug_id, s.fee, " .
                        "LEFT(fe.date, 10) AS encdate,fe.last_level_closed " .
                        "FROM form_encounter AS fe left join drug_sales AS s " .
                        "on s.pid = ? AND s.fee != 0 " .//AND s.billed = 0
                        "AND fe.pid = s.pid AND fe.encounter = s.encounter " .
                        "where fe.pid = ? " .
                        "ORDER BY s.encounter";

                        $dres = sqlStatement($query, array($pid, $pid));
                        //
                        while ($drow = sqlFetchArray($dres)) {
                            $key = 0 - $drow['encounter'];
                            if (empty($encs[$key])) {
                                $encs[$key] = array(
                                'encounter' => $drow['encounter'],
                                'date' => $drow['encdate'],
                                'last_level_closed' => $drow['last_level_closed'],
                                'charges' => 0,
                                'payments' => 0);
                            }

                            $encs[$key]['charges'] += $drow['fee'];
                            // Add taxes.
                            $trow = sqlQuery("SELECT taxrates FROM drug_templates WHERE drug_id = ? " .
                            "ORDER BY selector LIMIT 1", array($drow['drug_id']));
                            $encs[$key]['charges'] += calcTaxes($trow, $drow['fee']);
                        }

                        ksort($encs, SORT_NUMERIC);
                        $gottoday = false;
                        //Bringing on top the Today always
                        foreach ($encs as $key => $value) {
                            $dispdate = $value['date'];
                            if (strcmp($dispdate, $today) == 0 && !$gottoday) {
                                $gottoday = true;
                                break;
                            }
                        }

                        // If no billing was entered yet for today, then generate a line for
                        // entering today's co-pay.
                        //
                        if (!$gottoday) {
                            echoLine("form_upay[0]", date("Y-m-d"), 0, 0, 0, 0 /*$duept*/);//No encounter yet defined.
                        }

                        $gottoday = false;
                        foreach ($encs as $key => $value) {
                            $enc = $value['encounter'];
                            $dispdate = $value['date'];
                            if (strcmp($dispdate, $today) == 0 && !$gottoday) {
                                $dispdate = date("Y-m-d");
                                $gottoday = true;
                            }

                            //------------------------------------------------------------------------------------
                            $inscopay = getCopay($pid, $dispdate);
                            $patcopay = getPatientCopay($pid, $enc);
                            //Insurance Payment
                            //-----------------
                            $drow = sqlQuery(
                                "SELECT  SUM(pay_amount) AS payments, " .
                                "SUM(adj_amount) AS adjustments  FROM ar_activity WHERE " .
                                "pid = ? and encounter = ? and " .
                                "payer_type != 0 and account_code!='PCP' ",
                                array($pid, $enc)
                            );
                            $dpayment = $drow['payments'];
                            $dadjustment = $drow['adjustments'];
                            //Patient Payment
                            //---------------
                            $drow = sqlQuery(
                                "SELECT  SUM(pay_amount) AS payments, " .
                                "SUM(adj_amount) AS adjustments  FROM ar_activity WHERE " .
                                "pid = ? and encounter = ? and " .
                                "payer_type = 0 and account_code!='PCP' ",
                                array($pid, $enc)
                            );
                            $dpayment_pat = $drow['payments'];

                            //------------------------------------------------------------------------------------
                            //NumberOfInsurance
                            $ResultNumberOfInsurance = sqlStatement("SELECT COUNT( DISTINCT TYPE ) NumberOfInsurance FROM insurance_data
			where pid = ? and provider>0 ", array($pid));
                            $RowNumberOfInsurance = sqlFetchArray($ResultNumberOfInsurance);
                            $NumberOfInsurance = $RowNumberOfInsurance['NumberOfInsurance'] * 1;
                            //------------------------------------------------------------------------------------
                            $duept = 0;
                            if ((($NumberOfInsurance == 0 || $value['last_level_closed'] == 4 || $NumberOfInsurance == $value['last_level_closed']))) {//Patient balance
                                $brow = sqlQuery("SELECT SUM(fee) AS amount FROM billing WHERE " .
                                    "pid = ? and encounter = ? AND activity = 1", array($pid, $enc));
                                $srow = sqlQuery("SELECT SUM(fee) AS amount FROM drug_sales WHERE " .
                                    "pid = ? and encounter = ? ", array($pid, $enc));
                                $drow = sqlQuery("SELECT SUM(pay_amount) AS payments, " .
                                    "SUM(adj_amount) AS adjustments FROM ar_activity WHERE " .
                                    "pid = ? and encounter = ? ", array($pid, $enc));
                                $duept = $brow['amount'] + $srow['amount'] - $drow['payments'] - $drow['adjustments'];
                            }

                            echoLine(
                                "form_upay[$enc]",
                                $dispdate,
                                $value['charges'],
                                $dpayment_pat,
                                ($dpayment + $dadjustment),
                                $duept,
                                $enc,
                                $inscopay,
                                $patcopay
                            );
                        }


                        // Continue with display of the data entry form.
                        ?>

                        <tr bgcolor="#cccccc">
                        <td class="dehead" id='td_total_1'></td>
                        <td class="dehead" id='td_total_2'></td>
                        <td class="dehead" id='td_total_3'></td>
                        <td class="dehead" id='td_total_4'></td>
                        <td class="dehead" id='td_total_5'></td>
                        <td class="dehead" id='td_total_6'></td>
                        <td class="dehead" id='td_total_7'></td>
                        <td class="dehead" id='td_total_8'></td>
                        <td class="dehead" align="right">
                            <?php echo htmlspecialchars(xl('Total'), ENT_QUOTES); ?>
                        </td>
                        <td class="dehead" align="right">
                            <input type='text' name='form_paytotal' value=''
                                   style='color:#00aa00;width:50px' readonly/>
                        </td>
                        
                    </tr>
                    <!-- Begin Medic Coin Module -->
                    <tr id="paytotal_medic" bgcolor="#cccccc">
                        <td class="dehead" align="right" colspan="10">
                            <?php echo htmlspecialchars(xl('Total in MEDIC'), ENT_QUOTES); ?>
                            <input type='text' class="text form-control" name='form_paytotal_medic' id='form_paytotal_medic' value=''
                                   style='color:#00aa00;width:110px;display:inline-block;' readonly />
                        </td>
                    </tr>
                    <input type='hidden' name='medic_usd' id='medic_usd' value='<?php echo $GLOBALS['medic_usd'];?>'/>
                    <!-- End Medic Coin Module -->
                    </table>

                    <span class="text-center">
    <input type='submit' name='form_save' value='<?php echo htmlspecialchars(xl('Generate Invoice'), ENT_QUOTES); ?>'/> &nbsp;
    <input type='button' value='<?php echo xla('Cancel'); ?>' onclick='closeHow(event)'/>

    <input type="hidden" name="hidden_patient_code" id="hidden_patient_code" value="<?php echo attr($pid); ?>"/>
    <input type='hidden' name='ajax_mode' id='ajax_mode' value=''/>
    <input type='hidden' name='mode' id='mode' value=''/>
    </span>
                </form>
                <script language="JavaScript">
                    calctotal();
                </script>
            </div>
        </div>
        </body>

        <?php
    }
?>
</html>
