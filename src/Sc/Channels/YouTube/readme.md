put text in ./shared/3e4r5t6y.txt
bash -c "echo 'y' | tts --text \"\$(cat /app/shared/3e4r5t6y.txt)\" --model_name tts_models/multilingual/multi-dataset/xtts_v2 --language_idx ru --speaker_wav /app/shared/reference3.wav --out_path /app/shared/3e4r5t6y.wav"
ffmpeg -loop 1 -i 3e4r5t6y.jpg -i 3e4r5t6y.wav -c:v libx264 -tune stillimage -c:a aac -b:a 192k -shortest -pix_fmt yuv420p 3e4r5t6y.mp4