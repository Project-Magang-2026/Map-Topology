@echo off
title MikroTik GIS Network - Fast & Lightweight
echo ========================================================
echo  MikroTik GIS Topology & FTTH Command Center
echo ========================================================
echo.
echo Membuka dashboard di browser http://127.0.0.1:8000 ...
start http://127.0.0.1:8000
echo Menjalankan server Laravel...
cd /d c:\laragon\www\mikrotik-topology
php artisan serve --host=127.0.0.1 --port=8000
