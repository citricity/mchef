# Disclaimer / License Agree Feature

## Status - implemented

## Description

On first usage of Mchef (regardless of the command / arguments), it should spit out the following disclaimer followed by an "I agree Y/N" input prompt:

> MChef is provided "as is" without warranty of any kind, express or implied.
>
> By using MChef, you acknowledge and agree that:
>
> You use this software entirely at your own risk.
>
> The author(s) shall not be held liable for any data loss, database corruption, system damage, service interruption, loss of earnings, loss of business opportunity, or any other direct or indirect damages arising from its use.
>
> It is your responsibility to ensure appropriate backups are taken before running commands that modify or rebuild environments.
>
> MChef is intended for development and testing purposes. It should not be used in production environments without proper review, safeguards, and understanding of its behaviour.
>
> No guarantee is made regarding compatibility with specific versions of Moodle, Docker, operating systems, or third-party services.
>
> This disclaimer is in addition to the terms set out in the LICENSE file.
> Please review the LICENSE for the full legal terms governing use of this software.
>
> If you do not agree with these terms, do not use this software.

After selecting "Y" it should write a TERMSAGREED.txt file to Configurator::instance()->configDir() containing the current date as the agreed on date + user name if available.
Every time an mchef command is called it will check that TERMSAGREED.txt exists in the configDir() or it will again ask the user to agree to terms.

PHP Unit tests will be affected by this change - MchefTestCase.php should be modified to add TERMSAGREED.txt to Configurator::instance()->configDir() by default.

A PHP Unit test should be created to make sure that you can't call Mchef commands until you agree to terms. The commands it uses in this test should be "ListAll" and "Use".
