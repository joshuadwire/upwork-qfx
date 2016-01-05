# Upwork Transactions QFX Creator

## Features
* Downloads transactions from Upwork and creates a QFX file that can be imported into Quicken
* Calculates a balance by adding up the transactions since the most recent withdrawal

## Caveats
* Does not handle users who hire freelancers
* Does not handle earning reversals

## Usage
1. [Register for an Upwork API key](https://www.upwork.com/services/api/apply)
2. Give the project the following access levels:
   - Access your basic info
   - View the structure of your companies/teams
   - Generate time and financial reports for your companies and teams
3. Set the Upwork project callback URL to the URL to qfx.php. This will work even if qfx.php is not publicly accessible.
4. Rename config-sample.php to config.php and set your consumer key and consumer secret
5. Load qfx.php in your browser and authenticate with Upwork
6. Once authentication is complete, a QFX file will be downloaded.
7. In Quicken, click File->Import->Web Connect File
8. Select the account to import to