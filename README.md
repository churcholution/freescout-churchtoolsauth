# FreeScout Module - ChurchTools Auth
Login to [FreeScout](https://github.com/freescout-helpdesk/freescout "FreeScout") with [ChurchTools](https://www.church.tools "ChurchTools") credentials and manage permissions based on group/role memberships.

<img src="Public/img/churchtoolsauth-256x256@2x.png" width="192" height="192" style="border-radius: 1em;" />

## Screenshots

![User login with ChurchTools credentials](Public/img/screenshot-01.jpg)

![Connection to ChurchTools and definition of administrators](Public/img/screenshot-02.jpg)

![Group/role assignments for a mailbox](Public/img/screenshot-03.jpg)

![Synchronization of user profiles](Public/img/screenshot-04.jpg)

## Install
1. Navigate to your Modules folder e.g. `cd /var/www/html/Modules`
2. Run `git clone https://github.com/churcholution/freescout-churchtoolsauth.git ChurchToolsAuth`
3. Run `chown -R www-data:www-data ChurchToolsAuth` (or whichever user:group your webserver uses)
4. Activate the Module in the FreeScout Manage > Modules menu.

## Update
1. Navigate to the ChurchToolsAuth folder e.g. `cd /var/www/html/Modules/ChurchToolsAuth`
2. Run `git pull`
3. Run `chown -R www-data:www-data .` (or whichever user:group your webserver uses)
4. Enjoy the update!

© [Churcholution GmbH](https://www.churcholution.ch "Churcholution GmbH") | All rights reserved.