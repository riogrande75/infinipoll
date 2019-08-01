# infinipoll
Logger script for Voltronic / Infini-Solar / MPP-Solar / Effekta-HX / Westech HYS hybrid inverter based on Voltronic protocol 16.

It's a script that queries all kind of interresting data from a Infinisolar 3k/3k+ or clone hybrid inverter. It's written quick'n'dirty in php and works fine for me since years running on a RPi2.
All findings of my investigations were implemented, which should at least a bit explain this spaghetti code.
All values get written in simple text files in the /tmp directory and can be taken over in other visalisation programs (123solar, meterN, mqtt,...)
Pls. excuse comments in german language, been to lazy to translate this.
Feel free to discuss any ideas, bugs or improvements here or better in forum [url]https://www.photovoltaikforum.com/thread/115416-infinisolar-3k-10k-logging-und-feedin-control/[/url]
