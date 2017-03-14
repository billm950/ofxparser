<?php

namespace OfxParser;

use SimpleXMLElement;
use OfxParser\Entities\AccountInfo;
use OfxParser\Entities\BankAccount;
use OfxParser\Entities\Institute;
use OfxParser\Entities\SignOn;
use OfxParser\Entities\Statement;
use OfxParser\Entities\Status;
use OfxParser\Entities\Transaction;
use OfxParser\Entities\InvestmentTransaction;
use OfxParser\Entities\HoldingsInfo;
use OfxParser\Entities\InvestmentPosition;
/**
 * The OFX object
 *
 * Heavily refactored from Guillaume Bailleul's grimfor/ofxparser
 *
 * Second refactor by Oliver Lowe to unify the API across all
 * OFX data-types.
 *
 * Based on Andrew A Smith's Ruby ofx-parser
 *
 * @author Guillaume BAILLEUL <contact@guillaume-bailleul.fr>
 * @author James Titcumb <hello@jamestitcumb.com>
 * @author Oliver Lowe <mrtriangle@gmail.com>
 */
class Ofx
{
    /**
     * @var SignOn
     */
    public $signOn;

    /**
     * @var AccountInfo[]
     */
    public $signupAccountInfo;

    /**
     * @var BankAccount[]
     */
    public $bankAccounts = [];

    /**
     * Only populated if there is only one bank account
     * @var BankAccount|null
     * @deprecated This will be removed in future versions
     */
    public $bankAccount;

    /**
     * @param SimpleXMLElement $xml
     * @throws \Exception
     */
    public function __construct(SimpleXMLElement $xml)
    {
        $this->signOn = $this->buildSignOn($xml->SIGNONMSGSRSV1->SONRS);
        $this->signupAccountInfo = $this->buildAccountInfo($xml->SIGNUPMSGSRSV1->ACCTINFOTRNRS);

        if (isset($xml->BANKMSGSRSV1)) {
            $this->bankAccounts = $this->buildBankAccounts($xml);
        } elseif (isset($xml->CREDITCARDMSGSRSV1)) {
            $this->bankAccounts = $this->buildCreditAccounts($xml);
        } elseif (isset($xml->INVSTMTMSGSRSV1)) {
            $this->bankAccounts = $this->buildInvestmentAccounts($xml);
        } elseif (isset($xml->SECLISTMSGSRSV1)) {
            $this->bankAccounts = $this->buildInvestmentHoldings($xml);
        }

        // Set a helper if only one bank account
        if (count($this->bankAccounts) === 1) {
            $this->bankAccount = $this->bankAccounts[0];
        }
    }

    /**
     * Get the transactions that have been processed
     *
     * @return array
     * @deprecated This will be removed in future versions
     */
    public function getTransactions()
    {
        return $this->bankAccount->statement->transactions;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return SignOn
     * @throws \Exception
     */
    private function buildSignOn(SimpleXMLElement $xml)
    {
        $signOn = new SignOn();
        $signOn->status = $this->buildStatus($xml->STATUS);
        $signOn->date = $this->createDateTimeFromStr($xml->DTSERVER, true);
        $signOn->language = $xml->LANGUAGE;

        $signOn->institute = new Institute();
        $signOn->institute->name = $xml->FI->ORG;
        $signOn->institute->id = $xml->FI->FID;

        return $signOn;
    }

    /**
     * @param SimpleXMLElement|null $xml
     * @return array AccountInfo
     */
    private function buildAccountInfo(SimpleXMLElement $xml = null)
    {
        if (null === $xml || !isset($xml->ACCTINFO)) {
            return [];
        }

        $accounts = [];
        foreach ($xml->ACCTINFO as $account) {
            $accountInfo = new AccountInfo();
            $accountInfo->desc = $account->DESC;
            $accountInfo->number = $account->ACCTID;
            $accounts[] = $accountInfo;
        }

        return $accounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildCreditAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];

        foreach ($xml->CREDITCARDMSGSRSV1->CCSTMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildCreditAccount($accountStatement);
        }
        return $bankAccounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildBankAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];
        foreach ($xml->BANKMSGSRSV1->STMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildBankAccount($accountStatement);
        }
        return $bankAccounts;
    }


    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildInvestmentAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];
        foreach ($xml->INVSTMTMSGSRSV1->INVSTMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildInvestmentAccount($accountStatement);
        }
        return $bankAccounts;
    }


    /**
     * @param SimpleXMLElement $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildBankAccount(SimpleXMLElement $xml)
    {
        $bankAccount = new BankAccount();
        $bankAccount->transactionUid = $xml->TRNUID;
        $bankAccount->agencyNumber = $xml->STMTRS->BANKACCTFROM->BRANCHID;
        $bankAccount->accountNumber = $xml->STMTRS->BANKACCTFROM->ACCTID;
        $bankAccount->routingNumber = $xml->STMTRS->BANKACCTFROM->BANKID;
        $bankAccount->accountType = $xml->STMTRS->BANKACCTFROM->ACCTTYPE;
        $bankAccount->balance = $xml->STMTRS->LEDGERBAL->BALAMT;
        $bankAccount->balanceDate = $this->createDateTimeFromStr($xml->STMTRS->LEDGERBAL->DTASOF, true);

        $bankAccount->statement = new Statement();
        $bankAccount->statement->currency = $xml->STMTRS->CURDEF;
        $bankAccount->statement->startDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTSTART);
        $bankAccount->statement->endDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTEND);
        $bankAccount->statement->transactions = $this->buildTransactions($xml->STMTRS->BANKTRANLIST->STMTTRN);

        return $bankAccount;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildCreditAccount(SimpleXMLElement $xml)
    {
        $nodeName = 'CCACCTFROM';
        if (!isset($xml->CCSTMTRS->$nodeName)) {
            $nodeName = 'BANKACCTFROM';
        }

        $creditAccount = new BankAccount();
        $creditAccount->transactionUid = $xml->TRNUID;
        $creditAccount->agencyNumber = $xml->CCSTMTRS->$nodeName->BRANCHID;
        $creditAccount->accountNumber = $xml->CCSTMTRS->$nodeName->ACCTID;
        $creditAccount->routingNumber = $xml->CCSTMTRS->$nodeName->BANKID;
        $creditAccount->accountType = $xml->CCSTMTRS->$nodeName->ACCTTYPE;
        $creditAccount->balance = $xml->CCSTMTRS->LEDGERBAL->BALAMT;
        $creditAccount->balanceDate = $this->createDateTimeFromStr($xml->CCSTMTRS->LEDGERBAL->DTASOF, true);

        $creditAccount->statement = new Statement();
        $creditAccount->statement->currency = $xml->CCSTMTRS->CURDEF;
        $creditAccount->statement->startDate = $this->createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTSTART);
        $creditAccount->statement->endDate = $this->createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTEND);
        $creditAccount->statement->transactions = $this->buildTransactions($xml->CCSTMTRS->BANKTRANLIST->STMTTRN);

        return $creditAccount;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildInvestmentAccount(SimpleXMLElement $xml)
    {
        $nodeName = 'INVACCTFROM';

        $investmentAccount = new BankAccount();
        $investmentAccount->transactionUid = $xml->TRNUID;
        $investmentAccount->accountNumber = (string) $xml->INVSTMTRS->$nodeName->ACCTID;
        $investmentAccount->brokerId = (string) $xml->INVSTMTRS->$nodeName->BROKERID;
        $investmentAccount->balance = (float) $xml->INVSTMTRS->INVBAL->AVAILCASH;
        $investmentAccount->marginBalance = (float) $xml->INVSTMTRS->INVBAL->MARGINBALANCE;
        $investmentAccount->buyPower = (float) $xml->INVSTMTRS->INVBAL->BUYPOWER;
        $investmentAccount->marketValue = (float) $xml->INVSTMTRS->INVBAL->BALLIST->BAL->VALUE;
        $investmentAccount->balanceDate = $this->createDateTimeFromStr($xml->INVSTMTRS->DTASOF, true);
        $investmentAccount->marketValueDate = $this->createDateTimeFromStr($xml->INVSTMTRS->INVBAL->BALLIST->BAL->DTASOF, true);

        $investmentAccount->statement = new Statement();
        $investmentAccount->statement->currency = $xml->INVSTMTRS->CURDEF;
        $investmentAccount->statement->startDate = $this->createDateTimeFromStr($xml->INVSTMTRS->INVTRANLIST->DTSTART, true);
        $investmentAccount->statement->endDate = $this->createDateTimeFromStr($xml->INVSTMTRS->INVTRANLIST->DTEND, true);

        $investmentAccount->statement->transactions = new InvestmentTransaction();
        if ($xml->INVSTMTRS->INVTRANLIST->INVBANKTRAN) $investmentAccount->statement->transactions->otherTransactions    = $this->buildInvBankTransactions($xml->INVSTMTRS->INVTRANLIST->INVBANKTRAN);
        if ($xml->INVSTMTRS->INVTRANLIST->BUYOTHER) $investmentAccount->statement->transactions->buyOtherTransactions = $this->buildBuyTransactions($xml->INVSTMTRS->INVTRANLIST->BUYOTHER);
        if ($xml->INVSTMTRS->INVTRANLIST->BUYSTOCK) $investmentAccount->statement->transactions->buyStockTransactions = $this->buildBuyTransactions($xml->INVSTMTRS->INVTRANLIST->BUYSTOCK);
        if ($xml->INVSTMTRS->INVTRANLIST->INCOME) $investmentAccount->statement->transactions->incomeTransactions   = $this->buildIncomeTransactions($xml->INVSTMTRS->INVTRANLIST->INCOME);
        if ($xml->INVSTMTRS->INVTRANLIST->TRANSFER) $investmentAccount->statement->transactions->TransferTransactions   = $this->buildIncomeTransactions($xml->INVSTMTRS->INVTRANLIST->TRANSFER);
        
        $investmentAccount->statement->stockPositions = new Statement();
        if ($xml->INVSTMTRS->INVPOSLIST->POSSTOCK) $investmentAccount->statement->stockPositions->stocks             = $this->buildStockPositions($xml->INVSTMTRS->INVPOSLIST->POSSTOCK);
        if ($xml->INVSTMTRS->INVPOSLIST->POSOTHER) $investmentAccount->statement->stockPositions->other              = $this->buildStockPositions($xml->INVSTMTRS->INVPOSLIST->POSOTHER);

        return $investmentAccount;
    }

    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildInvBankTransactions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new Transaction();
            $transaction->type = (string)$t->STMTTRN->TRNTYPE;
            $transaction->date = $this->createDateTimeFromStr($t->STMTTRN->DTPOSTED, true);
            if ('' !== (string)$t->DTUSER) {
                $transaction->userInitiatedDate = $this->createDateTimeFromStr($t->DTUSER, true);
            }
            $transaction->amount = (float) $t->STMTTRN->TRNAMT;
            $transaction->uniqueId = (string)$t->STMTTRN->FITID;
            $transaction->name = (string)$t->STMTTRN->NAME;
            $transaction->memo = (string)$t->STMTTRN->MEMO;

            $return[] = $transaction;
        }

        return $return;
    }


    /**
     * @param SimpleXMLElement $positions
     * @return array
     * @throws \Exception
     */
    private function buildHoldingsInfo(SimpleXMLElement $positions)
    {
        $return = [];
        foreach ($postions as $p) {
            $positions = new HoldingsInfo();
            $positions->type = (string) $p->STOCKINFO->STOCKTYPE;
            $positions->secid = (string) $p->STOCKINFO->SECID->UNIQUEID;

            $positions->name = (string) $p->STOCKINFO->SECNAME;
            $positions->ticker = (string) $p->STOCKINFO->TICKER;
            $positions->yield = (float) $p->STOCKINFO->YIELD;
            
            $return[] = $positions;
        }

        return $return;
    }


    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildStockPositions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new InvestmentPosition();
            $transaction->type = (string)$t->INVPOS->POSTYPE;
            $transaction->dateValue = $this->createDateTimeFromStr($t->INVPOS->DTPRICEASOF, true);

            $transaction->total = (float)$t->INVPOS->MKTVAL;
            $transaction->memo = (string)$t->INVPOS->MEMO;
            $transaction->secid = (string) $t->INVPOS->SECID->UNIQUEID;
            $transaction->units = (float) $t->INVPOS->UNITS;
            $transaction->unitPrice = (float) $t->INVPOS->UNITPRICE;
            
            $return[] = $transaction;
        }

        return $return;
    }


    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildIncomeTransactions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new InvestmentTransaction();
            $transaction->type = (string)$t->INCOMETYPE;
            $transaction->date = $this->createDateTimeFromStr($t->INVTRAN->DTTRADE);
            
            if ('' !== (string)$t->INVTRAN->DTUSER) {
                $transaction->userInitiatedDate = $this->createDateTimeFromStr($t->INVTRAN->DTUSER);
            }

            $transaction->amount = (float) $t->TOTAL;
            $transaction->uniqueId = (string)$t->INVTRAN->FITID;
            $transaction->memo = (string)$t->INVTRAN->MEMO;
            $transaction->secid = (string) $t->SECID->UNIQUEID;
            $return[] = $transaction;
        }

        return $return;
    }


    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildBuyTransactions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new InvestmentTransaction();
            $transaction->type = (string)$t->BUYTYPE;
            $transaction->date = $this->createDateTimeFromStr($t->INVBUY->INVTRAN->DTTRADE);
            $transaction->date_settle = $this->createDateTimeFromStr($t->INVBUY->INVTRAN->DTSETTLE);
            
            if ('' !== (string)$t->INVBUY->INVTRAN->DTUSER) {
                $transaction->userInitiatedDate = $this->createDateTimeFromStr($t->INVBUY->INVTRAN->DTUSER);
            }

            $transaction->amount = (float) $t->INVBUY->TOTAL;
            $transaction->uniqueId = (string) $t->INVBUY->INVTRAN->FITID;
            $transaction->memo = (string) $t->INVBUY->INVTRAN->MEMO;
            $transaction->secid = (string) $t->INVBUY->SECID->UNIQUEID;
            $transaction->units = (float) $t->INVBUY->UNITS;
            $transaction->unitprice = (float) $t->INVBUY->UNITPRICE;
            $transaction->fees = (float) $t->INVBUY->FEES;
            $return[] = $transaction;
        }

        return $return;
    }


    /**
     * @param SimpleXMLElement $transactions
     * @return array
     * @throws \Exception
     */
    private function buildTransactions(SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $transaction = new Transaction();
            $transaction->type = (string)$t->TRNTYPE;
            $transaction->date = $this->createDateTimeFromStr($t->DTPOSTED, true);
            if ('' !== (string)$t->DTUSER) {
                $transaction->userInitiatedDate = $this->createDateTimeFromStr($t->DTUSER, true);
            }
            $transaction->amount = (float) $t->TRNAMT;
            $transaction->uniqueId = (string)$t->FITID;
            $transaction->name = (string)$t->NAME;
            $transaction->memo = (string)$t->MEMO;
            $transaction->sic = $t->SIC;
            $transaction->checkNumber = $t->CHECKNUM;
            $return[] = $transaction;
        }

        return $return;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return Status
     */
    private function buildStatus(SimpleXMLElement $xml)
    {
        $status = new Status();
        $status->code = $xml->CODE;
        $status->severity = $xml->SEVERITY;
        $status->message = $xml->MESSAGE;

        return $status;
    }

    /**
     * Create a DateTime object from a valid OFX date format
     *
     * Supports:
     * YYYYMMDDHHMMSS.XXX[gmt offset:tz name]
     * YYYYMMDDHHMMSS.XXX
     * YYYYMMDDHHMMSS
     * YYYYMMDD
     *
     * @param  string $dateString
     * @param  boolean $ignoreErrors
     * @return \DateTime $dateString
     * @throws \Exception
     */
    private function createDateTimeFromStr($dateString, $ignoreErrors = false)
    {
        $regex = '/'
            . "(\d{4})(\d{2})(\d{2})?"     // YYYYMMDD             1,2,3
            . "(?:(\d{2})(\d{2})(\d{2}))?" // HHMMSS   - optional  4,5,6
            . "(?:\.(\d{3}))?"             // .XXX     - optional  7
            . "(?:\[(-?\d+)\:(\w{3}\]))?"  // [-n:TZ]  - optional  8,9
            . '/';

        if (preg_match($regex, $dateString, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = isset($matches[4]) ? $matches[4] : 0;
            $min = isset($matches[5]) ? $matches[5] : 0;
            $sec = isset($matches[6]) ? $matches[6] : 0;

            $format = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $min . ':' . $sec;

            try {
                return new \DateTime($format);
            } catch (\Exception $e) {
                if ($ignoreErrors) {
                    return null;
                }

                throw $e;
            }
        }

        //throw new \RuntimeException('Failed to initialize DateTime for string: ' . $dateString);
    }

    /**
     * Create a formatted number in Float according to different locale options
     *
     * Supports:
     * 000,00 and -000,00
     * 0.000,00 and -0.000,00
     * 0,000.00 and -0,000.00
     * 000.00 and 000.00
     *
     * @param  string $amountString
     * @return float
     */
    private function createAmountFromStr($amountString)
    {
        // Decimal mark style (UK/US): 000.00 or 0,000.00
        if (preg_match('/^-?([\d,]+)(\.?)([\d]{2})$/', $amountString) === 1) {
            return (float)preg_replace(
                ['/([,]+)/', '/\.?([\d]{2})$/'],
                ['', '.$1'],
                $amountString
            );
        }

        // European style: 000,00 or 0.000,00
        if (preg_match('/^-?([\d\.]+,?[\d]{2})$/', $amountString) === 1) {
            return (float)preg_replace(
                ['/([\.]+)/', '/,?([\d]{2})$/'],
                ['', '.$1'],
                $amountString
            );
        }

        return (float)$amountString;
    }
}
