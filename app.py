import calendar as pycal
import difflib
import io
import re
import json
import urllib.parse
import urllib.request
from pathlib import Path

import folium
import numpy as np 
import pandas as pd 
from flask import Flask, Response, render_template, request 
from folium.plugins import Draw

APP_NAME = "app"


