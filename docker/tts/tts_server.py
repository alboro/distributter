# docker/tts/tts_server.py
from fastapi import FastAPI, File, UploadFile, BackgroundTasks
from fastapi.responses import FileResponse
import subprocess
import os

app = FastAPI()

@app.post("/synthesize")
async def synthesize(text: str, callback_url: str = None):
    output_file = f"/app/shared/{uuid.uuid4()}.wav"

    # Синтез в фоне
    background_tasks.add_task(process_tts, text, output_file, callback_url)

    return {"task_id": "123", "status": "processing"}

@app.post("/upload")
async def upload_file(file: UploadFile = File(...)):
    content = await file.read()
    with open(f"/app/uploads/{file.filename}", "wb") as f:
        f.write(content)
    return {"filename": file.filename}

@app.get("/download/{file_id}")
async def download_file(file_id: str):
    file_path = f"/app/shared/{file_id}.wav"
    return FileResponse(file_path, filename=f"{file_id}.wav")

async def process_tts(text: str, output_file: str, callback_url: str):
    # Выполняем синтез
    subprocess.run(['quick-tts', text, output_file])

    # Отправляем коллбек
    if callback_url:
        requests.post(callback_url, json={
            "status": "completed",
            "download_url": f"/download/{os.path.basename(output_file)}"
        })