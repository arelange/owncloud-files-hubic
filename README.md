owncloud-files-hubic
====================

Owncloud external storage support: HubiC cloud service.

Hubic is an online storage service by OVH.
Plans start with 25 GB free.
More information about HubiC on http://hubic.com.

This app provides you  the ability to mount your Hubic storage into your OwnCloud instance through the "External Storage Support" of OwnCloud.

*Note: neither this app nor its author are affiliated with or related to OVH.*

Admins
------
#### Register your domain on HubiC
In order to be allowed to use HubiC API, you must register the domain of your owncloud instance.
Visit https://hubic.com/home/browser/developers/ and clic on "add an application". You are asked some information:
* Last Name: just a name to identify your several registrations
* Redirection Domains: the domain name of your OwnCloud instance.
Clic "OK" and your new record should have appeared in the list.
Clic on "details" in the line of your new record and copy the fields "Client ID" and "Secret Client". They will be used later.

#### Configure your OwnCloud mount point
More information on External Storage support basics: http://doc.owncloud.org/server/7.0/admin_manual/configuration/custom_mount_config_gui.html

Go to your Admin page in OwnCloud, create your new folder name, enter the "Client ID" and "Client Secret" you got at step 1, select your users and groups, and clic "Grant Access".
You will be redirected on the HubiC login page.
Type your personal HubiC identifiers to allow your OwnCloud instance to access your files and clic on "Accept".
You are redirected back on your owncloud configuration page and your mount point should display "Access granted".

Developpers
-----------
Hubic is based on an OpenStack Swift infrastructure. Although, HubiC does not use the OpenStack authentification, but a custom OAuth2 process the get the Swift credentials.

For more information, please visit https://api.hubic.com/ .

Maintainers
-----------
The original developper created this app for his personal use. He took the time publish it, although he may not have the time to care about every single bug report. If you feel the braveness of being the maintainer, you are welcome !
