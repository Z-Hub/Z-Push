Name:       z-push
Version:    1.0.0
Release:    1
Summary:    An implementation of Microsoft's ActiveSync protocol
Group:      Productivity/Networking/Email/Utilities
License:    AGPL-3.0
BuildArch:  noarch
URL:        http://z-push.org/
Source:     %name-%version.tar.gz
BuildRoot:  %_tmppath/%name-%version-build
%define zpush_dir %_datadir/z-push

%if 0%{?suse_version}
    %define apache_dir %_sysconfdir/apache2
%else
    %if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
        %define apache_dir /opt/rh/httpd24/root/etc/httpd/
    %else
        %define apache_dir %_sysconfdir/httpd
    %endif
%endif

%description
Z-push is an implementation of the ActiveSync protocol which is used 'over-the-air' for multi platform ActiveSync devices. Devices supported are including Windows Mobile, Android, iPhone, and Nokia. With Z-push any groupware can be connected and synced with these devices.

%package -n %name-common
Summary:    Z-Push core package
Group:      Productivity/Networking/Email/Utilities

%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56
Requires:   rh-php56-php-soap
Requires:   rh-php56-php-mbstring
Requires:   rh-php56-php-process
%else
Requires:   php >= 5.4.0
Requires:   php-soap
Requires:   php-mbstring
%if 0%{?suse_version}
Requires:   php-posix
%else
Requires:   php-process
%endif
%endif
%description -n %name-common
Z-push is an implementation of the ActiveSync protocol which is used 'over-the-air' for multi platform ActiveSync devices. Devices supported are including Windows Mobile, Android, iPhone, and Nokia. With Z-push any groupware can be connected and synced with these devices.

# CALDAV
%package -n %name-backend-caldav
Summary:    Z-Push caldav backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   php-awl
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-common
Requires:   rh-php56-php-xml
%else
Requires:   php-curl
Requires:   php-xml
%endif

Provides:   %name-backend

%description -n %name-backend-caldav
Backend for Z-Push, that adds the ability to connect to a caldav server

# CARDDAV
%package -n %name-backend-carddav
Summary:    Z-Push carddav backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   php-curl
Requires:   php-xsl
Provides:   %name-backend

%description -n %name-backend-carddav
Backend for Z-Push, that adds the ability to connect to a carddav server

# COMBINED
%package -n %name-backend-combined
Summary:    Z-Push combined backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Provides:   %name-backend

%description -n %name-backend-combined
Backend for Z-Push, that adds the ability to combine backends.

# IMAP
%package -n %name-backend-imap
Summary:    Z-Push imap backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   php-awl
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-imap
%else
Requires:   php-imap
%endif
Provides:   %name-backend

%description -n %name-backend-imap
Backend for Z-Push, that adds the ability to connect to a imap server

# LDAP
%package -n %name-backend-ldap
Summary:    Z-Push ldap backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-ldap
%else
Requires:   php-ldap
%endif
Provides:   %name-backend

%description -n %name-backend-ldap
Backend for Z-Push, that adds the ability to connect to a ldap server

# KOPANO
%package -n %name-backend-kopano
Summary:    Z-Push Kopano backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if 0%{?fedora_version} || 0%{?centos_version} || 0%{?rhel_version}
Requires:   php-mapi-webapp
%else
Requires:   php-mapi
%endif
Provides:   %name-backend

%description -n %name-backend-kopano
Backend for Z-Push, that adds the ability to connect to a Kopano server

%package -n %name-kopano
Summary:    Z-Push for Kopano
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   %name-backend-kopano = %version
Requires:   %name-ipc-sharedmemory = %version

%description -n %name-kopano
Z-Push for Kopano meta package

%package -n %name-kopano-gabsync
Summary:    GAB sync for Kopano
Group:      Productivity/Networking/Email/Utilities
%if 0%{?fedora_version} || 0%{?centos_version} || 0%{?rhel_version}
Requires:   php-mapi-webapp
%else
Requires:   php-mapi
%endif

%description -n %name-kopano-gabsync
Synchronizes a Kopano global address book

%package -n %name-kopano-gab2contacts
Summary:    GAB sync into a contacts folder for Kopano
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if 0%{?fedora_version} || 0%{?centos_version} || 0%{?rhel_version}
Requires:   php-mapi-webapp
%else
Requires:   php-mapi
%endif

%description -n %name-kopano-gab2contacts
Synchronizes a Kopano global address book into a contacts folder

# IPC SHARED MEMORY
%package -n %name-ipc-sharedmemory
Summary:    Z-Push ipc shared memory provider
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-process
%else
Requires:   php-sysvshm
Requires:   php-sysvsem
Requires:   php-pcntl
%endif

%description -n %name-ipc-sharedmemory
Provider for Z-Push, that adds the ability to use ipc shared memory

# IPC MEMCACHED
%package -n %name-ipc-memcached
Summary:    Z-Push ipc memcached provider
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   memcached
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-memcached
%else
%if 0%{?suse_version}
Requires:   php5-memcached
%else
Requires:   php-pecl-memcached
%endif
%endif

%description -n %name-ipc-memcached
Provider for Z-Push, that adds the ability to use ipc memcached

# GALSEARCH LDAP
%package -n %name-galsearch-ldap
Summary:    Z-Push ldap search backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-ldap
%else
Requires:   php-ldap
%endif
Provides:   %name-backend

%description -n %name-galsearch-ldap
Backend for Z-Push, that adds the ability to search a ldap server

# STATE SQL
%package -n %name-state-sql
Summary:    Z-Push mysql state backend
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   rh-php56-php-mysqlnd
Requires:   rh-php56-php-pdo
%else
Requires:   php-mysql
Requires:   php-pdo
%endif

%description -n %name-state-sql
Backend for Z-Push, that adds the ability to save states in a mysql database

# AUTODISCOVER
%package -n %name-autodiscover
Summary:    Z-Push autodiscover
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
Requires:   php-xml
Requires:   %name-backend

%description -n %name-autodiscover
Autodiscover for Z-Push backends

# CONFIG
%package -n %name-config-apache
Summary:    Z-Push apache configuration
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-common = %version
%if 0%{?suse_version}
Requires:   apache2
Requires:   mod_php_any
%else
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   httpd24-httpd
%else
Requires:   httpd
%endif
%endif

%description -n %name-config-apache
Z-push apache configuration files

%package -n %name-config-apache-autodiscover
Summary:    Z-Push autodiscover apache configuration
Group:      Productivity/Networking/Email/Utilities
Requires:   %name-autodiscover = %version
%if 0%{?suse_version}
Requires:   apache2
Requires:   mod_php_any
%else
%if "%_repository" == "RHEL_6_PHP_56" || "%_repository" == "RHEL_7_PHP_56"
Requires:   httpd24-httpd
%else
Requires:   httpd
%endif
%endif

%description -n %name-config-apache-autodiscover
Z-push autodiscover apache configuration files

# CONFIG NGINX
%package -n %name-config-nginx
Summary:    Z-Push nginx configuration
Group:      Productivity/Networking/Email/Utilities
Requires:   nginx

%description -n %name-config-nginx
Z-push nginx configuration files

%prep
%setup -q

%build

%install
b="%buildroot";
bdir="$b/%zpush_dir/backend";
cdir="$b/%_sysconfdir/z-push";

mkdir -p "$b/%zpush_dir"
cp -a src/* "$b/%zpush_dir/"
rm -f "$b/%zpush_dir/"{INSTALL,LICENSE}

# COMMON
# set version number
sed -s "s/ZPUSHVERSION/%version/" build/version.php.in > "$b/%zpush_dir/version.php"

mkdir -p "$b/%_sysconfdir/z-push";

mv "$b/%zpush_dir/config.php" "$cdir/z-push.conf.php";
ln -s "%_sysconfdir/z-push/z-push.conf.php" "$b/%zpush_dir/config.php";

mv "$b/%zpush_dir/policies.ini" "$cdir/policies.ini";
ln -s "%_sysconfdir/z-push/policies.ini" "$b/%zpush_dir/policies.ini";

mkdir -p "$b/%_bindir"
ln -s "%zpush_dir/z-push-admin.php" "$b/%_bindir/z-push-admin";
ln -s "%zpush_dir/z-push-top.php" "$b/%_bindir/z-push-top";

mkdir -p "$b/%_localstatedir/lib/z-push";
mkdir -p "$b/%_localstatedir/log/z-push";
mkdir -p "$b/%_sysconfdir/logrotate.d";
install -Dpm 644 config/z-push-rhel.lr \
    "$b/%_sysconfdir/logrotate.d/z-push.lr"

# CALDAV
mv "$bdir/caldav/config.php" "$cdir/caldav.conf.php";
ln -s "%_sysconfdir/z-push/caldav.conf.php" "$bdir/caldav/config.php";

# CARDDAV
mv "$bdir/carddav/config.php" "$cdir/carddav.conf.php";
ln -s "%_sysconfdir/z-push/carddav.conf.php" "$bdir/carddav/config.php";

# COMBINED
mv "$bdir/combined/config.php" "$cdir/combined.conf.php";
ln -s "%_sysconfdir/z-push/combined.conf.php" "$bdir/combined/config.php";

# IMAP
mv "$bdir/imap/config.php" "$cdir/imap.conf.php";
ln -s "%_sysconfdir/z-push/imap.conf.php" "$bdir/imap/config.php";

# LDAP
mv "$bdir/ldap/config.php" "$cdir/ldap.conf.php";
ln -s "%_sysconfdir/z-push/ldap.conf.php" "$bdir/ldap/config.php";

# KOPANO
mv "$bdir/kopano/config.php" "$cdir/kopano.conf.php";
ln -s "%_sysconfdir/z-push/kopano.conf.php" "$bdir/kopano/config.php";

# GAB-SYNC
mkdir -p "$b/%zpush_dir/tools"
cp -a tools/gab-sync "$b/%zpush_dir/tools/"
mv "$b/%zpush_dir/tools/gab-sync/config.php" "$cdir/gabsync.conf.php";
ln -s "%_sysconfdir/z-push/gabsync.conf.php" "$b/%zpush_dir/tools/gab-sync/config.php";
mkdir -p "$b/%_bindir"
ln -s "%zpush_dir/tools/gab-sync/gab-sync.php" "$b/%_bindir/z-push-gabsync";

# GAB2CONTACTS
mkdir -p "$b/%zpush_dir/tools"
cp -a tools/gab2contacts "$b/%zpush_dir/tools/"
mv "$b/%zpush_dir/tools/gab2contacts/config.php" "$cdir/gab2contacts.conf.php";
ln -s "%_sysconfdir/z-push/gab2contacts.conf.php" "$b/%zpush_dir/tools/gab2contacts/config.php";
sed -i -s "s/PATH_TO_ZPUSH', '\.\.\/\.\.\/src\/')/PATH_TO_ZPUSH', '\/usr\/share\/z-push\/')/" "$b/%zpush_dir/tools/gab2contacts/gab2contacts.php"
mkdir -p "$b/%_bindir"
ln -s "%zpush_dir/tools/gab2contacts/gab2contacts.php" "$b/%_bindir/z-push-gab2contacts";

# MEMCACHED
mv "$bdir/ipcmemcached/config.php" "$cdir/memcached.conf.php";
ln -s "%_sysconfdir/z-push/memcached.conf.php" "$bdir/ipcmemcached/config.php";

# GALSEARCH LDAP
mv "$bdir/searchldap/config.php" "$cdir/galsearch-ldap.conf.php";
ln -s "%_sysconfdir/z-push/galsearch-ldap.conf.php" "$bdir/searchldap/config.php";

# STATE SQL
mv "$bdir/sqlstatemachine/config.php" "$cdir/state-sql.conf.php";
ln -s "%_sysconfdir/z-push/state-sql.conf.php" "$bdir/sqlstatemachine/config.php";

cp -a tools/migrate-filestates-to-db.php "$b/%zpush_dir/tools/"

# AUTODISCOVER
mv "$b/%zpush_dir/autodiscover/config.php" "$cdir/autodiscover.conf.php";
ln -s "%_sysconfdir/z-push/autodiscover.conf.php" "$b/%zpush_dir/autodiscover/config.php";

# APACHE
mkdir -p "$b/%apache_dir/conf.d";
install -Dpm 644 config/apache2/z-push.conf \
    "$b/%apache_dir/conf.d/z-push.conf";
install -Dpm 644 config/apache2/z-push-autodiscover.conf \
    "$b/%apache_dir/conf.d/z-push-autodiscover.conf";

# NGINX
mkdir -p "$b/%_sysconfdir/nginx/sites-available/";
mkdir -p "$b/%_sysconfdir/nginx/sites-enabled/";
install -Dpm 644 config/nginx/z-push.conf "$b/%_sysconfdir/nginx/sites-available/z-push.conf"

# MANPAGES
mkdir -p "$b/%_mandir/man1"
cp man/*.1 "$b/%_mandir/man1"

%post -n %name-config-apache
%if 0%{?suse_version}
    service apache2 reload || true
%else
    service httpd reload || true
%endif

%post -n %name-config-apache-autodiscover
%if 0%{?suse_version}
    a2enmod alias || true
    service apache2 reload || true
%else
    service httpd reload || true
%endif

%post -n %name-config-nginx
ln -s "%_sysconfdir/nginx/sites-available/z-push.conf" "%_sysconfdir/nginx/sites-enabled/"
service nginx reload

%postun -n %name-config-apache
%if 0%{?suse_version}
    service apache2 reload || true
%else
    service httpd reload || true
%endif

%postun -n %name-config-apache-autodiscover
%if 0%{?suse_version}
    service apache2 reload || true
%else
    service httpd reload || true
%endif

%postun -n %name-config-nginx
rm -f "%_sysconfdir/nginx/sites-available/z-push.conf" "%_sysconfdir/nginx/sites-enabled/"
service nginx reload

# COMMON
%files -n %name-common
%defattr(-, root, root)
%dir %_sysconfdir/z-push

%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/policies.ini
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/z-push.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/policies.ini
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/z-push.conf.php
%endif

%config(noreplace) %attr(0640,root,root) %_sysconfdir/logrotate.d/z-push.lr

%exclude %zpush_dir/backend
%exclude %zpush_dir/autodiscover
%exclude %zpush_dir/tools/migrate-filestates-to-db.php
%exclude %zpush_dir/tools/gab-sync
%exclude %zpush_dir/tools/gab2contacts
%zpush_dir/
%doc src/LICENSE

%if 0%{?suse_version}
%attr(750,wwwrun,www) %dir %_localstatedir/lib/z-push
%attr(750,wwwrun,www) %dir %_localstatedir/log/z-push
%else
%attr(750,apache,apache) %dir %_localstatedir/lib/z-push
%attr(750,apache,apache) %dir %_localstatedir/log/z-push
%endif

%_bindir/z-push-admin
%_bindir/z-push-top

%_mandir/man1/z-push-admin.1*
%_mandir/man1/z-push-top.1*

# CALDAV
%files -n %name-backend-caldav
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/caldav
%zpush_dir/backend/caldav/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/caldav.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/caldav.conf.php
%endif

# CARDDAV
%files -n %name-backend-carddav
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/carddav
%zpush_dir/backend/carddav/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/carddav.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/carddav.conf.php
%endif

# COMBINED
%files -n %name-backend-combined
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/combined
%zpush_dir/backend/combined/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/combined.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/combined.conf.php
%endif

# IMAP
%files -n %name-backend-imap
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/imap
%zpush_dir/backend/imap/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/imap.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/imap.conf.php
%endif

# LDAP
%files -n %name-backend-ldap
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/ldap
%zpush_dir/backend/ldap/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/ldap.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/ldap.conf.php
%endif

# KOPANO
%files -n %name-backend-kopano
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/kopano
%zpush_dir/backend/kopano/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/kopano.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/kopano.conf.php
%endif

%files -n %name-kopano-gabsync
%defattr(-, root, root)
%dir %zpush_dir/tools
%dir %zpush_dir/tools/gab-sync
%zpush_dir/tools/gab-sync/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/gabsync.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/gabsync.conf.php
%endif
%_bindir/z-push-gabsync
%_mandir/man1/z-push-gabsync.1*

%files -n %name-kopano-gab2contacts
%defattr(-, root, root)
%dir %zpush_dir/tools
%dir %zpush_dir/tools/gab2contacts
%zpush_dir/tools/gab2contacts/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/gab2contacts.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/gab2contacts.conf.php
%endif
%_bindir/z-push-gab2contacts
%_mandir/man1/z-push-gab2contacts.1*

%files -n %name-kopano

# IPC-SHAREDMEMORY
%files -n %name-ipc-sharedmemory
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/ipcsharedmemory/
%zpush_dir/backend/ipcsharedmemory

# IPC-MEMCACHED
%files -n %name-ipc-memcached
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/ipcmemcached
%zpush_dir/backend/ipcmemcached/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/memcached.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/memcached.conf.php
%endif

# GALSEARCH-LDAP
%files -n %name-galsearch-ldap
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/searchldap
%zpush_dir/backend/searchldap/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/galsearch-ldap.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/galsearch-ldap.conf.php
%endif

# STATE-SQL
%files -n %name-state-sql
%defattr(-, root, root)
%dir %zpush_dir/backend
%dir %zpush_dir/backend/sqlstatemachine
%zpush_dir/backend/sqlstatemachine/
%zpush_dir/tools/migrate-filestates-to-db.php
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/state-sql.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/state-sql.conf.php
%endif

# AUTODISCOVER
%files -n %name-autodiscover
%defattr(-, root, root)
%dir %zpush_dir/autodiscover
%zpush_dir/autodiscover/
%dir %_sysconfdir/z-push
%if 0%{?suse_version}
    %config(noreplace) %attr(0640,root,www) %_sysconfdir/z-push/autodiscover.conf.php
%else
    %config(noreplace) %attr(0640,root,apache) %_sysconfdir/z-push/autodiscover.conf.php
%endif

# CONFIG
%files -n %name-config-apache
%dir %apache_dir
%dir %apache_dir/conf.d
%config(noreplace) %attr(0640,root,root) %apache_dir/conf.d/z-push.conf

%files -n %name-config-apache-autodiscover
%dir %apache_dir
%dir %apache_dir/conf.d
%config(noreplace) %attr(0640,root,root) %apache_dir/conf.d/z-push-autodiscover.conf

# NGINX CONFIG
%files -n %name-config-nginx
%dir %_sysconfdir/nginx
%dir %_sysconfdir/nginx/sites-available
%dir %_sysconfdir/nginx/sites-enabled
%config(noreplace) %attr(0640,nginx,nginx) %_sysconfdir/nginx/sites-available/z-push.conf
%config(noreplace) %attr(0640,nginx,nginx) %_sysconfdir/z-push/*.php
%attr(750,nginx,nginx) %dir %_localstatedir/lib/z-push
%attr(750,nginx,nginx) %dir %_localstatedir/log/z-push

%changelog
