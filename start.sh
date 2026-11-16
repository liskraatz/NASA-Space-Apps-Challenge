#!/bin/bash
gunicorn --bind 127.0.0.1:5000 --workers 1 --threads 2 app:app &
apache2ctl -D FOREGROUND